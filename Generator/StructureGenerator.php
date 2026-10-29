<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SensitiveShapeDetector;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\PriorCodegenEvaluator;
use Smithy\Codegen\ShapeCategory;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates PHP classes from Smithy structure shapes.
 *
 * Produces `final readonly class` with:
 * - Promoted constructor properties (every member nullable with `= null`,
 *   ordered service-required first, defaulted second, optional last)
 * - `schema()` method with lazy caching
 * - codec-facing `serialize*()` methods plus `FromMembersTrait`
 */
final class StructureGenerator
{
    /**
     * Exception owns these properties, so modeled error members need private
     * backing properties instead of constructor promotion.
     */
    private const INHERITED_EXCEPTION_PROPERTIES = [
        'message' => 'modeledMessage',
        'code' => 'modeledCode',
        'file' => 'modeledFile',
        'line' => 'modeledLine'
    ];

    private readonly SensitiveShapeDetector $sensitiveShapeDetector;

    private readonly string $errorParentClass;

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings,
        /**
         * Prior-generation evaluator that surfaces historical member
         * ordering (via {@see PriorCodegenEvaluator::memberOrderFor()}).
         * Null on first-time codegen or when
         * `CodegenSettings::$preserveExistingMemberOrder` is off - in
         * both cases the generator falls back to the fresh
         * required-first / defaulted / optional partition. When
         * non-null, each shape's historical slot order wins and any
         * new-in-model members are appended at the tail.
         */
        private readonly ?PriorCodegenEvaluator $priorCodegen = null,
        ?SensitiveShapeDetector $sensitiveShapeDetector = null
    )
    {
        $this->sensitiveShapeDetector = $sensitiveShapeDetector
            ?? new SensitiveShapeDetector($model);
        $this->errorParentClass = $this->resolveErrorParentClass();
    }

    /**
     * Generate PHP class code for a structure shape.
     *
     * @param string $shapeId Fully-qualified shape ID (e.g., "smithy.example#Human")
     * @return GeneratedFile The generated file content and path
     */
    public function generate(string $shapeId): GeneratedFile
    {
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException("Shape not found: {$shapeId}");
        }

        // Error-shape names already carry the `Exception` suffix at
        // this layer. PhpSymbolProviderInterface::toShortClassName()
        // owns that rule so every generator produces the same name.
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);
        $paginationTokenMember =
            $this->model->getPaginationInputTokenMember($shapeId);
        $paginationPageSizeMember =
            $this->model->getPaginationPageSizeMember($shapeId);

        $writer = new PhpCodeWriter($namespace);

        // Every generated structure carries the full encode/decode contract.
        // Reachability used to gate whether `serializeMembers` / `fromFields`
        // were emitted. that gating is gone - the structure files are
        // symmetric across client, server, protocol tests, and mocking, and
        // the small bytecode cost of an unused method body is preferable to
        // the consumer-visible asymmetry. The local flags stay `true` so the
        // existing branch structure (HTTP-binding detection, trait imports,
        // method emission) runs unchanged.
        $isSerializable = true;
        $isDeserializable = true;

        // Detect if this shape has any HTTP binding members (for both input and
        // output shapes)
        $hasHttpBindings = false;
        $hasHttpResponseBindings = false;
        $httpTraitIds = [
            'smithy.api#httpHeader',
            'smithy.api#httpQuery',
            'smithy.api#httpLabel',
            'smithy.api#httpPayload',
            'smithy.api#httpPrefixHeaders',
            'smithy.api#httpQueryParams'
        ];
        $httpResponseTraitIds = [
            'smithy.api#httpHeader', 'smithy.api#httpPrefixHeaders',
            'smithy.api#httpPayload', 'smithy.api#httpResponseCode'
        ];
        if ($isSerializable) {
            foreach ($this->model->getMembers($shapeId) as $memberDef) {
                foreach ($httpTraitIds as $traitId) {
                    if (isset($memberDef['traits'][$traitId])) {
                        $hasHttpBindings = true;
                        break 2;
                    }
                }
            }
        }
        if ($isDeserializable) {
            foreach ($this->model->getMembers($shapeId) as $memberDef) {
                foreach ($httpResponseTraitIds as $traitId) {
                    if (isset($memberDef['traits'][$traitId])) {
                        $hasHttpResponseBindings = true;
                        break 2;
                    }
                }
            }
        }

        // Every structure implements `SerializableStructInterface`, which
        // covers the schema, the encode contract (`serialize`,
        // `serializeMembers`, `getMemberValue`), and the decode contract
        // (`fromFields`). The struct interface extends
        // `SerializableShapeInterface` (the universal `serialize($s, $idx)`
        // marker), so generated code only declares the leaf.
        $writer->addImport('Smithy\\Core\\Serde\\SerializableStructInterface');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        if ($paginationTokenMember !== null) {
            $writer->addImport(
                'Smithy\\Client\\Pagination\\PaginatedRequestInterface'
            );
        }
        if ($paginationPageSizeMember !== null) {
            $writer->addImport(
                'Smithy\\Client\\Pagination\\'
                . 'PageSizePaginatedRequestInterface'
            );
        }

        // Determine if ArrayAccess should be generated
        $withArrayAccess = $this->settings->shouldGenerateArrayAccess(
            $category
        );
        if ($withArrayAccess) {
            $writer->addImport('Smithy\\Core\\Shape\\ReadonlyArrayAccessTrait');
        }

        // Determine if this is an error shape
        $isError = $category === ShapeCategory::Error;
        $errorFault = null;
        if ($isError) {
            $errorTraitValue = $this->model->getTrait(
                $shapeId,
                'smithy.api#error'
            );
            $errorFault = is_string($errorTraitValue)
                ? $errorTraitValue
                : 'other';
            $writer->addImport('Smithy\\Core\\Exception\\ErrorFault');
        }

        // Register modeled member imports before resolving the error parent.
        // This allows a generated service exception to use an alias when a
        // member type has the same short name.
        foreach ($this->model->getMembers($shapeId) as $memberName => $_) {
            $phpType = $this->symbolProvider->toMemberType(
                $shapeId,
                $memberName
            );
            if ($phpType->requiresImport() && $phpType->fqcn !== null) {
                $writer->addImport($phpType->fqcn);
            }
        }

        // SensitiveStringableTrait provides redaction-aware string and debug
        // output. Emit it when this shape can transitively contain sensitive
        // data, including through unions and collections.
        $withSensitiveStringable = $this->shouldEmitSensitiveStringable(
            $shapeId
        );
        $jsonSerializable = null;
        if ($withSensitiveStringable) {
            $jsonSerializable = $writer->addImportReference(
                'JsonSerializable',
                'PhpJsonSerializable',
                [$className]
            );
            $writer->addImport(
                'Smithy\\Core\\Shape\\SensitiveStringableTrait'
            );
        }

        // FromMembersTrait provides the `@internal` `fromMembers()` sink used
        // by the runtime deserializer. Every generated struct participates in
        // the codec deserialization path, so the trait applies unconditionally.
        $writer->addImport('Smithy\\Core\\Shape\\FromMembersTrait');



        // Write doc comment from shape documentation trait
        $this->writeClassDoc($writer, $shapeId);

        // Build interface list. Every structure implements
        // `SerializableStructInterface` unconditionally. ArrayAccess opts
        // in when codegen settings request it.
        $parts = ['SerializableStructInterface'];
        if ($paginationTokenMember !== null) {
            $parts[] = 'PaginatedRequestInterface';
        }
        if ($paginationPageSizeMember !== null) {
            $parts[] = 'PageSizePaginatedRequestInterface';
        }
        if ($withArrayAccess) {
            $parts[] = '\\ArrayAccess';
        }
        if ($jsonSerializable !== null) {
            $parts[] = $jsonSerializable;
        }

        // Open the class.
        //
        // Note: shapes cannot be declared `final readonly class` even though
        // every promoted property below is `readonly`. PHP's class-level
        // `readonly` modifier implicitly applies readonly to *all* declared
        // properties, including statics - but static properties cannot be
        // readonly (the once-write semantic is meaningless for shared state).
        // Each shape declares `private static Schema $SCHEMA.` for the lazy
        // schema cache populated on first `schema()` call, so the class-level
        // modifier is incompatible with our schema-caching pattern.
        if ($isError) {
            $parentClass = $this->errorParentReference($writer, $className);
            $writer->writeClassDeclaration(
                "final class {$className} extends {$parentClass}",
                $parts
            );
        } else {
            $writer->writeClassDeclaration(
                "final class {$className}",
                $parts
            );
        }


        $writer->openBlock('{');

        // Add trait uses. FromMembersTrait always emits because every
        // generated struct participates in the deserialization path.
        if ($withArrayAccess) {
            $writer->writeLine('use ReadonlyArrayAccessTrait;');
        }
        if ($withSensitiveStringable) {
            $writer->writeLine('use SensitiveStringableTrait;');
        }
        $writer->writeLine('use FromMembersTrait;');
        $writer->writeLine();



        // Public shape-ID constant. Application code that needs the
        // Smithy ID for logging, metrics, or type-registry lookup reads
        // this constant instead of stringly-typing the ID.
        $writer->writeLine(
            "public const SHAPE_ID = '" . \addslashes($shapeId) . "';"
        );

        foreach ($this->settings->shapeConstants[$shapeId] ?? []
            as $name => $value
        ) {
            $escaped = \addcslashes($value, "\\'");
            $writer->writeLine(
                "public const {$name} = '{$escaped}';"
            );
        }
        $writer->writeLine();

        // Private static schema properties
        $writer->writeLine('private static Schema $SCHEMA;');

        $members = $this->model->getMembers($shapeId);
        if (!empty($members)) {
            $membersIndexed = $this->getMembersIndexed($shapeId);
            foreach ($membersIndexed as $memberName => $memberDef) {
                $constName = $this->toSchemaConstName($memberName);
                $writer->writeLine("private static Schema \${$constName};");
            }
        }
        $writer->writeLine();

        // Generate the constructor. Pagination tokens, scalar enums,
        // effective defaults, and inherited exception names use explicit
        // properties. Other members use property promotion.
        $this->writeConstructor(
            $writer,
            $shapeId,
            $isSerializable,
            $isError,
            $errorFault
        );


        $writer->writeLine();

        // Generate schema() method with lazy caching
        $this->writeSchemaMethod($writer, $shapeId);

        if ($paginationTokenMember !== null) {
            $writer->writeLine();
            $this->writePaginationTokenMethods(
                $writer,
                $shapeId,
                $paginationTokenMember
            );
        }
        if ($paginationPageSizeMember !== null) {
            $writer->writeLine();
            $this->writePaginationPageSizeMethods(
                $writer,
                $shapeId,
                $paginationPageSizeMember
            );
        }

        $writer->writeLine();

        // Generate the universal `serialize()` entry point + the
        // struct-specific `serializeMembers()` and `getMemberValue()`.
        // Reachability gating is gone, so every structure carries the full
        // encode contract.
        if ($isSerializable) {
            $this->writeSerializeMethod($writer);
            $writer->writeLine();
            $this->writeSerializeMembersMethod($writer, $shapeId);
            $writer->writeLine();
            $this->writeGetMemberValueMethod($writer, $shapeId);
            $writer->writeLine();
        }

        // `fromMembers()` (the deserializer's positional sink) is provided
        // by `FromMembersTrait`, added to the `use` list above. No per-shape
        // codegen for it.


        // Array conversion lives in the runtime serializer utility. Generated
        // structures do not carry shape-local `toArray()` / `fromArray()`.

        // Close the class
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function writeClassDoc(PhpCodeWriter $writer, string $shapeId): void
    {
        $docLines = [];

        $documentation = $this->model->getTrait(
            $shapeId,
            'smithy.api#documentation'
        );
        if ($documentation !== null) {
            $docLines[] = $this->cleanDocumentation((string) $documentation);
        }

        // @deprecated trait on shape
        if ($this->model->hasTrait($shapeId, 'smithy.api#deprecated')) {
            $deprecatedInfo = $this->model->getTrait(
                $shapeId,
                'smithy.api#deprecated'
            );
            $message = '';
            if (is_array($deprecatedInfo)) {
                $message = $deprecatedInfo['message'] ?? '';
                if (isset($deprecatedInfo['since'])) {
                    $message = "Since {$deprecatedInfo['since']}. " . $message;
                }
            }
            $docLines[] = '';
            $docLines[] = '@deprecated ' . trim($message);
        }

        // @sensitive trait on shape
        if ($this->model->hasTrait($shapeId, 'smithy.api#sensitive')) {
            $docLines[] = '';
            $docLines[] = '@sensitive Contains sensitive data. Do not log.';
        }

        if (!empty($docLines)) {
            $writer->writeDocBlock($docLines);
        }
    }

    /**
     * Get all members in constructor and schema-index order.
     *
     * Historical order wins when a prior state exists. Otherwise members
     * use required, defaulted, then optional order. Iteration index matches
     * the assigned `memberIndex`.
     */
    private function getMembersIndexed(string $shapeId): array
    {
        return $this->model->getMembersOrdered(
            $shapeId,
            $this->priorCodegen?->memberOrderFor($shapeId)
        );
    }

    /**
     * Whether a structure member targets a scalar enum or intEnum.
     *
     * Scalar enum members get the union-input pattern: the parameter
     * accepts the wire scalar (`MyEnum|string`/`MyIntEnum|int`) in addition
     * to the enum object, the property is declared separately above the
     * constructor, and a constructor body line normalizes string/int input
     * via `MyEnum::fromValue()`. Lists/maps of enums keep the existing
     * `array_map(MyEnum::fromValue, ...)` per-element hydration in
     * `fromArray()`/`fromFields()` and stay strictly typed at the property
     * level.
     */
    private function isScalarEnumMember(string $shapeId, string $memberName): bool
    {
        $members = $this->model->getMembers($shapeId);
        $targetId = $members[$memberName]['target'] ?? null;
        if ($targetId === null) {
            return false;
        }
        $targetType = $this->model->getShapeType($targetId);
        return $targetType === 'enum' || $targetType === 'intEnum';
    }

    /**
     * Resolve the wire-scalar PHP type that a scalar enum member's
     * constructor parameter accepts alongside the enum object. Strings for
     * `enum`, ints for `intEnum`. Falls back to `string` if the target is
     * unknown (defensive - `isScalarEnumMember()` should screen first).
     */
    private function enumWireType(string $shapeId, string $memberName): string
    {
        $members = $this->model->getMembers($shapeId);
        $targetId = $members[$memberName]['target'] ?? null;
        if ($targetId === null) {
            return 'string';
        }
        return $this->model->getShapeType($targetId) === 'intEnum'
            ? 'int'
            : 'string';
    }

    /**
     * Render a Smithy node value as a PHP expression.
     */
    private function renderDefaultLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // PHP requires a decimal in float literals to avoid coercion to
            // int.
            $rendered = (string) $value;
            return str_contains($rendered, '.') || str_contains($rendered, 'e')
                ? $rendered : $rendered . '.0';
        }
        if (is_string($value)) {
            return "'"
                . str_replace(["\\", "'"], ["\\\\", "\\'"], $value)
                . "'";
        }
        if (is_array($value) && $value === []) {
            return '[]';
        }
        // Should not reach here - isMemberDefaulted() screens unsupported
        // cases.
        throw new \LogicException(
            'Unsupported @default value: ' . var_export($value, true)
        );
    }

    /**
     * Render an effective member default in its PHP runtime representation.
     */
    private function renderMemberDefaultExpression(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): string
    {
        $targetId = $this->model->getMemberTarget($shapeId, $memberName);
        $targetType = $targetId === null
            ? null
            : $this->model->getShapeType($targetId);
        $value = $this->model->getMemberDefault($shapeId, $memberName);

        return match ($targetType) {
            'blob' => $this->renderBlobDefault($value),
            'timestamp' => $this->renderTimestampDefault($value),
            'float', 'double' => $this->renderFloatDefault($value),
            'bigInteger' => 'new BigInteger('
                . $this->renderDefaultLiteral((string) $value)
                . ')',
            'bigDecimal' => 'new BigDecimal('
                . $this->renderDefaultLiteral((string) $value)
                . ')',
            'document' => $this->renderDocumentDefault($writer, $value),
            default => $this->renderDefaultLiteral($value)
        };
    }

    private function renderBlobDefault(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \LogicException('Blob @default must be base64 text.');
        }

        $decoded = \base64_decode($value, true);
        if ($decoded === false) {
            throw new \LogicException('Blob @default is not valid base64.');
        }
        if ($decoded === '') {
            return "''";
        }

        $literal = '';
        for ($i = 0, $length = \strlen($decoded); $i < $length; $i++) {
            $literal .= \sprintf('\\x%02X', \ord($decoded[$i]));
        }

        return '"' . $literal . '"';
    }

    private function renderTimestampDefault(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return $this->renderFloatDefault($value);
        }
        if (!is_string($value)) {
            throw new \LogicException(
                'Timestamp @default must be a number or date-time string.'
            );
        }

        try {
            $timestamp = new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \LogicException(
                'Timestamp @default is not a valid date-time.',
                previous: $exception
            );
        }

        return $this->renderFloatDefault(
            (float) $timestamp->format('U.u')
        );
    }

    private function renderFloatDefault(mixed $value): string
    {
        if (is_string($value)) {
            return match ($value) {
                'NaN' => 'NAN',
                'Infinity' => 'INF',
                '-Infinity' => '-INF',
                default => throw new \LogicException(
                    'Floating-point @default must be numeric or special.'
                )
            };
        }
        if (!is_int($value) && !is_float($value)) {
            throw new \LogicException(
                'Floating-point @default must be numeric or special.'
            );
        }

        $rendered = (string) (float) $value;
        return str_contains($rendered, '.')
            || str_contains(strtolower($rendered), 'e')
                ? $rendered
                : $rendered . '.0';
    }

    private function renderDocumentDefault(
        PhpCodeWriter $writer,
        mixed $value
    ): string
    {
        $writer->addImport(
            'Smithy\\Core\\Shape\\Document\\AbstractDocument'
        );

        return 'AbstractDocument::fromValue('
            . $this->renderDefaultLiteral($value)
            . ')';
    }


    private function writeConstructor(
        PhpCodeWriter $writer,
        string $shapeId,
        bool $isSerializable = true,
        bool $isError = false,
        ?string $errorFault = null
    ): void
    {
        $members = $this->model->getMembers($shapeId);

        if (empty($members)) {
            if ($isError) {
                $writer->writeLine('public function __construct()');
                $writer->openBlock('{');
                $writer->writeLine(
                    "parent::__construct('', ErrorFault::"
                    . $this->toErrorFaultCase($errorFault)
                    . ');'
                );
                $writer->closeBlock();
            } else {
                $writer->writeLine('public function __construct() {}');
            }
            return;
        }

        $allMembers = $this->getMembersIndexed($shapeId);
        $paginationTokenMember =
            $this->model->getPaginationInputTokenMember($shapeId);
        $paginationPageSizeMember =
            $this->model->getPaginationPageSizeMember($shapeId);

        // Scalar enum/intEnum and effective-default members are declared
        // separately and assigned in the constructor body.
        $explicitlyDeclared = [];
        foreach ($allMembers as $memberName => $memberDef) {
            if ($this->isScalarEnumMember($shapeId, $memberName)
                || $this->model->isMemberDefaulted($shapeId, $memberName)
                || $memberName === $paginationTokenMember
                || $memberName === $paginationPageSizeMember
                || $this->isInheritedExceptionProperty(
                    $shapeId,
                    $memberName
                )
            ) {
                $explicitlyDeclared[$memberName] = $memberDef;
            }
        }

        if (!empty($explicitlyDeclared)) {
            foreach ($explicitlyDeclared as $memberName => $memberDef) {
                if ($memberName === $paginationTokenMember
                    || $memberName === $paginationPageSizeMember
                ) {
                    $this->writeMutablePaginationProperty(
                        $writer,
                        $shapeId,
                        $memberName
                    );
                } elseif ($this->isScalarEnumMember(
                    $shapeId,
                    $memberName
                )) {
                    $this->writeExplicitEnumProperty(
                        $writer,
                        $shapeId,
                        $memberName
                    );
                } elseif ($this->isInheritedExceptionProperty(
                    $shapeId,
                    $memberName
                )) {
                    $this->writeInheritedExceptionProperty(
                        $writer,
                        $shapeId,
                        $memberName
                    );
                } else {
                    $this->writeExplicitDefaultedProperty(
                        $writer,
                        $shapeId,
                        $memberName
                    );
                }
            }
            $writer->writeLine();
        }

        // Collect PHPDoc lines
        $paramDocs = $this->buildParamDocs($shapeId, $allMembers);
        if (!empty($paramDocs)) {
            $writer->writeDocBlock($paramDocs);
        }

        $writer->writeLine('public function __construct(');
        $writer->pushIndent();

        $modeFor = function (string $name) use ($shapeId): string {
            if ($this->model->isMemberRequired($shapeId, $name)) {
                return 'required';
            }
            if ($this->model->isMemberDefaulted($shapeId, $name)) {
                return 'defaulted';
            }
            return 'optional';
        };

        foreach ($allMembers as $memberName => $memberDef) {
            $this->writeConstructorParam(
                $writer,
                $shapeId,
                $memberName,
                mode: $modeFor($memberName)
            );
        }

        $writer->popIndent();

        // Body assignments are required for errors and members that need
        // normalization or explicit storage. Schema initialization stays
        // lazy until serialization or explicit schema access.
        $needsBody = $isError
            || !empty($explicitlyDeclared);

        if ($needsBody) {
            $writer->writeLine(') {');
            $writer->pushIndent();
            if ($isError) {
                $messageParameter = $this->exceptionMessageParameter(
                    $shapeId
                );
                $message = $messageParameter === null
                    ? "''"
                    : "\${$messageParameter} ?? ''";
                $writer->writeLine(
                    "parent::__construct({$message}, ErrorFault::"
                    . $this->toErrorFaultCase($errorFault)
                    . ');'
                );
            }
            foreach ($explicitlyDeclared as $memberName => $memberDef) {
                if ($memberName === $paginationTokenMember
                    || $memberName === $paginationPageSizeMember
                ) {
                    $propertyName =
                        $this->symbolProvider->toPropertyName($memberName);
                    $writer->writeLine(
                        "\$this->{$propertyName} = \${$propertyName};"
                    );
                } elseif ($this->isScalarEnumMember(
                    $shapeId,
                    $memberName
                )) {
                    $this->writeEnumNormalizationAssignment(
                        $writer,
                        $shapeId,
                        $memberName,
                        $modeFor($memberName)
                    );
                } else {
                    $parameterName =
                        $this->symbolProvider->toPropertyName($memberName);
                    $propertyName = $this->toMemberStoragePropertyName(
                        $shapeId,
                        $memberName
                    );
                    if ($this->model->isMemberDefaulted(
                        $shapeId,
                        $memberName
                    )) {
                        $default = $this->renderMemberDefaultExpression(
                            $writer,
                            $shapeId,
                            $memberName
                        );
                        $writer->writeLine(
                            "\$this->{$propertyName} = "
                                . "\${$parameterName} ?? {$default};"
                        );
                    } else {
                        $writer->writeLine(
                            "\$this->{$propertyName} = \${$parameterName};"
                        );
                    }
                }
            }
            $writer->popIndent();
            $writer->writeLine('}');
        } else {
            $writer->writeLine(') {}');
        }
    }

    /**
     * Emit a private writable pagination property.
     *
     * @return void
     */
    private function writeMutablePaginationProperty(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType(
            $shapeId,
            $memberName
        );
        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        $writer->writeLine(
            "private {$phpType->nullable()} \${$propertyName};"
        );
    }

    /**
     * Emit private storage for a modeled member whose PHP name is already
     * owned by the exception hierarchy.
     */
    private function writeInheritedExceptionProperty(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType(
            $shapeId,
            $memberName
        );
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );

        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        $isDefaulted = $this->model->isMemberDefaulted(
            $shapeId,
            $memberName
        );
        $typeHint = $isDefaulted
            ? $phpType->typeHint
            : $phpType->nullable();

        $writer->writeLine(
            "private readonly {$typeHint} \${$propertyName};"
        );
    }

    /**
     * Emit a non-nullable property whose default is materialized in the
     * constructor body.
     */
    private function writeExplicitDefaultedProperty(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType(
            $shapeId,
            $memberName
        );
        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        $this->writeMemberAnnotations($writer, $shapeId, $memberName);
        $writer->writeLine(
            "public readonly {$phpType->typeHint} \${$propertyName};"
        );
    }

    /**
     * Emit pagination token access and immutable copy methods.
     *
     * @return void
     */
    private function writePaginationTokenMethods(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType(
            $shapeId,
            $memberName
        );
        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        $getterDocs = ['Get the pagination token.'];
        if ($phpType->docType !== null
            && $phpType->docType !== $phpType->typeHint
        ) {
            $getterDocs[] = '';
            $getterDocs[] = "@return {$phpType->nullableDocType()}";
        }
        $writer->writeDocBlock($getterDocs);
        $writer->writeLine(
            "public function getPaginationToken(): {$phpType->nullable()}"
        );
        $writer->openBlock('{');
        $writer->writeLine("return \$this->{$propertyName};");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock([
            'Create a copy with a pagination token.',
            '',
            "@param {$phpType->nullableDocType()} \$token"
        ]);
        $writer->writeLine(
            'public function withPaginationToken('
                . 'string|array|null $token): self'
        );
        $writer->openBlock('{');
        $writer->writeLine('$copy = clone $this;');
        $writer->writeLine("\$copy->{$propertyName} = \$token;");
        $writer->writeLine();
        $writer->writeLine('return $copy;');
        $writer->closeBlock();
    }

    /**
     * Emit pagination page-size access and immutable copy methods.
     *
     * @return void
     */
    private function writePaginationPageSizeMethods(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType(
            $shapeId,
            $memberName
        );
        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        $writer->writeDocBlock(['Get the pagination page size.']);
        $writer->writeLine(
            "public function getPaginationPageSize(): {$phpType->nullable()}"
        );
        $writer->openBlock('{');
        $writer->writeLine("return \$this->{$propertyName};");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock([
            'Create a copy with a pagination page size.'
        ]);
        $writer->writeLine(
            'public function withPaginationPageSize(int $pageSize): self'
        );
        $writer->openBlock('{');
        $writer->writeLine('$copy = clone $this;');
        $writer->writeLine("\$copy->{$propertyName} = \$pageSize;");
        $writer->writeLine();
        $writer->writeLine('return $copy;');
        $writer->closeBlock();
    }

    /**
     * Emit the standalone `public readonly MyEnum $name.` declaration that
     * sits above the constructor for a scalar enum/intEnum member. The
     * declared type is non-nullable for required and defaulted members and
     * nullable for the pure-optional case.
     */
    private function writeExplicitEnumProperty(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType($shapeId, $memberName);
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );

        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        $this->writeMemberAnnotations($writer, $shapeId, $memberName);

        // Defaulted enum members keep a non-nullable property (the constructor
        // body resolves the literal default via fromValue()). Required and
        // optional members both declare the property nullable.
        $isDefaulted = $this->model->isMemberDefaulted($shapeId, $memberName);
        $typeHint = $isDefaulted ? $phpType->typeHint : $phpType->nullable();

        $writer->writeLine("public readonly {$typeHint} \${$propertyName};");
    }

    /**
     * Emit the constructor-body line that normalizes a scalar enum/intEnum
     * member's input. The parameter is `MyEnum|<scalar>(|null)`. the
     * resolved property is always the enum object (or null on the optional
     * path). The `instanceof` branch is the fast path for the typed-object
     * case. the wire-scalar branch falls through to `MyEnum::fromValue()`.
     *
     * @param 'required'|'defaulted'|'optional' $mode
     */
    private function writeEnumNormalizationAssignment(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        string $mode
    ): void
    {
        $parameterName = $this->symbolProvider->toPropertyName($memberName);
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );
        $members = $this->model->getMembers($shapeId);
        $targetId = $members[$memberName]['target'] ?? null;
        $enumClass = $this->symbolProvider->toShortClassName(
            $targetId ?? 'smithy.api#String'
        );

        if ($mode === 'optional') {
            // Nullable parameter - guard the whole assignment so a null
            // input leaves the typed property uninitialized. This matches
            // the `isset($this->prop)` gate used by `serializeMembers()`
            // and avoids a redundant write when the user passed nothing.
            $writer->writeLine("if (\${$parameterName} !== null) {");
            $writer->pushIndent();
            $writer->writeLine(
                "\$this->{$propertyName} = \${$parameterName} "
                . "instanceof {$enumClass}"
                . " ? \${$parameterName}"
                . " : {$enumClass}::fromValue(\${$parameterName});"
            );
            $writer->popIndent();
            $writer->writeLine('}');
        } else {
            $value = "\${$parameterName}";
            if ($mode === 'defaulted') {
                $default = $this->renderMemberDefaultExpression(
                    $writer,
                    $shapeId,
                    $memberName
                );
                $value .= " ?? {$default}";
            }
            $writer->writeLine(
                "\$this->{$propertyName} = \${$parameterName} "
                . "instanceof {$enumClass}"
                . " ? \${$parameterName}"
                . " : {$enumClass}::fromValue({$value});"
            );
        }

    }

    private function writeMemberAnnotations(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName
    ): void
    {
        $members = $this->model->getMembers($shapeId);
        $memberTraits = $members[$memberName]['traits'] ?? [];
        $targetId = $members[$memberName]['target'] ?? null;
        $annotations = [];

        if (isset($memberTraits['smithy.api#deprecated'])) {
            $annotations[] = '@deprecated';
        }
        if (isset($memberTraits['smithy.api#sensitive'])
            || (
                $targetId !== null
                && $this->model->hasTrait($targetId, 'smithy.api#sensitive')
            )
        ) {
            $annotations[] = '@sensitive';
        }
        if ($annotations !== []) {
            $writer->writeLine('/** ' . implode(' ', $annotations) . ' */');
        }
    }


    /**
     * @param 'required'|'defaulted'|'optional' $mode
     */
    private function writeConstructorParam(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        string $mode
    ): void
    {
        $phpType = $this->symbolProvider->toMemberType($shapeId, $memberName);
        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        $isEnumMember = $this->isScalarEnumMember($shapeId, $memberName);
        $isPaginationToken = $memberName
            === $this->model->getPaginationInputTokenMember($shapeId);
        $isPaginationPageSize = $memberName
            === $this->model->getPaginationPageSizeMember($shapeId);
        $isPaginationMember =
            $isPaginationToken || $isPaginationPageSize;
        $isInheritedExceptionProperty =
            $this->isInheritedExceptionProperty($shapeId, $memberName);
        $isDefaulted = $mode === 'defaulted';

        // Annotations only attach to promoted-property declarations. Scalar
        // enum, effective-default, pagination, and inherited exception
        // members declare the property separately above the constructor.
        if (!$isEnumMember
            && !$isDefaulted
            && !$isPaginationMember
            && !$isInheritedExceptionProperty
        ) {
            $this->writeMemberAnnotations($writer, $shapeId, $memberName);
        }

        if ($isEnumMember) {
            // `MyEnum|string|null` for enum and `MyEnum|int|null` for
            // intEnum. The nullable slot distinguishes an omitted value;
            // effective defaults are materialized in the constructor body.
            $wireType = $this->enumWireType($shapeId, $memberName);
            $typeHint = "{$phpType->typeHint}|{$wireType}|null";
            $writer->writeLine(
                "{$typeHint} \${$propertyName} = null,"
            );
            return;
        }

        $typeHint = $phpType->nullable();
        $promotion = $isPaginationMember
            || $isInheritedExceptionProperty
            || $isDefaulted
                ? ''
                : 'public readonly ';
        $writer->writeLine(
            "{$promotion}{$typeHint} \${$propertyName} = null,"
        );
    }





    private function writeSchemaMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        // Symbol provider centralizes the error-suffix rule.
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $methodName = lcfirst($className);

        // Add Schemas import
        $schemasNamespace = $this->settings->namespace . '\\Schemas';
        $writer->addImport($schemasNamespace);

        $membersIndexed = $this->getMembersIndexed($shapeId);

        $writer->writeDocBlock(['Get the shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');

        if (empty($membersIndexed)) {
            // No members means no sibling schema statics to hydrate. Collapse
            // the isset-guard + assign pair into `??=` for a single-line body.
            $writer->writeLine(
                "return self::\$SCHEMA ??= Schemas::{$methodName}();"
            );
        } else {
            // Members present. The guard-plus-assign form stays because the
            // per-member `self::\$SCHEMA_*` statics must be hydrated before
            // returning, and `??=` cannot express a side-effecting expression.
            $writer->writeLine('if (isset(self::$SCHEMA)) {');
            $writer->pushIndent();
            $writer->writeLine('return self::$SCHEMA;');
            $writer->popIndent();
            $writer->writeLine('}');

            $writer->writeLine("\$s = Schemas::{$methodName}();");
            $writer->writeLine('$m = $s->getMembersByIndex();');
            $index = 0;
            foreach ($membersIndexed as $memberName => $memberDef) {
                $constName = $this->toSchemaConstName($memberName);
                $writer->writeLine("self::\${$constName} = \$m[{$index}];");
                $index++;
            }
            $writer->writeLine('return self::$SCHEMA = $s;');
        }

        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock(['Get the shape schema for this instance.']);
        $writer->writeLine('public function getSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::schema();');
        $writer->closeBlock();
    }

    /**
     * Propagate serialization-relevant traits from the target shape to the member traits.
     * Only adds traits that are NOT already present on the member (member takes precedence).
     *
     * This handles cases like:
     * - @timestampFormat on a target timestamp shape (e.g., EpochSeconds)
     * - @xmlNamespace on a target structure shape
     * - @xmlAttribute on a target (rare but possible)
     *
     * @return void
     */
    private function propagateTargetTraits(string $targetId, array &$traits): void
    {
        $targetShape = $this->model->getShape($targetId);
        if ($targetShape === null) {
            return;
        }

        $targetTraits = $targetShape['traits'] ?? [];

        // Traits that should propagate from target to member schema if not
        // already set
        $propagateList = [
            'smithy.api#timestampFormat',
            'smithy.api#xmlNamespace',
            'smithy.api#xmlAttribute',
            'smithy.api#streaming',
            // @mediaType propagates so the HTTP binding provider can detect
            // base64 encoding for `@httpHeader` members whose target string
            // carries the trait (e.g. `JsonValue`).
            'smithy.api#mediaType',
            // @sparse lives on the list/map *shape*. Propagate to the
            // member so the JSON codec's serializeList/serializeMap can
            // inspect it on the member schema directly.
            'smithy.api#sparse',
            // @sensitive lives on the target shape (e.g. `SecretString`).
            // Propagate so `SensitiveStringableTrait` and any consumer
            // walking a member schema sees the sensitivity flag without
            // resolving the target class first.
            'smithy.api#sensitive'
        ];


        foreach ($propagateList as $traitId) {
            if (!isset($traits[$traitId])
                && isset($targetTraits[$traitId])
            ) {
                $traits[$traitId] = $targetTraits[$traitId];
            }
        }
    }

    /**
     * Build serde-relevant traits for a member.
     *
     * @return array<string, mixed> Model trait ID => value. Keys map to int constants.
     */
    private function buildMemberTraits(array $memberTraits): array
    {
        $serdeTraits = [];

        foreach ($this->traitIndexMap() as $traitId => $index) {
            if (isset($memberTraits[$traitId])) {
                $serdeTraits[$traitId] = $memberTraits[$traitId];
            }
        }

        return $serdeTraits;
    }

    /** Map from Schema::T_* constant name to its int value (for packed array index) */
    private const TRAIT_INDEX_MAP = [
        'smithy.api#xmlName' => 0,        // Schema::T_XML_NAME
        'smithy.api#xmlNamespace' => 1,    // Schema::T_XML_NAMESPACE
        'smithy.api#xmlAttribute' => 2,    // Schema::T_XML_ATTRIBUTE
        'smithy.api#xmlFlattened' => 3,    // Schema::T_XML_FLATTENED
        'smithy.api#timestampFormat' => 4, // Schema::T_TIMESTAMP_FORMAT
        'smithy.api#httpHeader' => 5,      // Schema::T_HTTP_HEADER
        'smithy.api#httpLabel' => 6,       // Schema::T_HTTP_LABEL
        'smithy.api#httpPayload' => 7,     // Schema::T_HTTP_PAYLOAD
        'smithy.api#httpPrefixHeaders' => 8,  // Schema::T_HTTP_PREFIX_HEADERS
        'smithy.api#httpQuery' => 9,       // Schema::T_HTTP_QUERY
        'smithy.api#httpQueryParams' => 10,   // Schema::T_HTTP_QUERY_PARAMS
        'smithy.api#httpResponseCode' => 11,  // Schema::T_HTTP_RESPONSE_CODE
        'smithy.api#jsonName' => 12,       // Schema::T_JSON_NAME
        'smithy.api#mediaType' => 13,      // Schema::T_MEDIA_TYPE
        'smithy.api#required' => 14,       // Schema::T_REQUIRED
        'smithy.api#sparse' => 15,         // Schema::T_SPARSE
        'smithy.api#streaming' => 16,      // Schema::T_STREAMING
        'smithy.api#idempotencyToken' => 19, // Schema::T_IDEMPOTENCY_TOKEN
        'smithy.api#sensitive' => 20         // Schema::T_SENSITIVE
    ];


    /**
     * Render populated dense-trait indexes without null padding.
     */
    private function renderTraitsArray(array $traits): string
    {
        if (empty($traits)) {
            return '';
        }

        $indexed = [];
        $traitIndexMap = $this->traitIndexMap();
        foreach ($traits as $traitId => $value) {
            $idx = $traitIndexMap[$traitId] ?? null;
            if ($idx === null) {
                continue;
            }
            $indexed[$idx] = $value;
        }

        if ($indexed === []) {
            return '';
        }

        \ksort($indexed);
        $parts = [];
        foreach ($indexed as $index => $value) {
            if ($value === [] || $value === (object)[] || $value === null) {
                $literal = 'true';
            } else {
                $literal = PhpCodeWriter::exportLiteral($value);
            }
            $parts[] = $index . ' => ' . $literal;
        }

        return ', traits: [' . implode(', ', $parts) . ']';
    }

    /**
     * @return array<string, int>
     */
    private function traitIndexMap(): array
    {
        return self::TRAIT_INDEX_MAP
            + $this->settings->schemaTraitIndices;
    }

    /**
     * Strip HTML tags and clean up documentation text for PHPDoc.
     */
    private function cleanDocumentation(string $text): string
    {
        // Strip HTML tags, keeping content
        $text = strip_tags($text);
        // Collapse multiple whitespace/newlines into single spaces
        $text = preg_replace('/\s+/', ' ', $text);
        // Trim
        return trim($text);
    }

    /**
     * Word-wrap a @param docblock line at 80 chars.
     *
     * Continuation lines are indented with 8 spaces (after " * ") to align
     * under the type on the first line:
     *   " * @param string $name Description text..."
     *   " *        continuation text..."
     *
     * @param string $line The full @param line (without " * " prefix)
     * @return list<string> Wrapped lines (without " * " prefix)
     */
    private function wrapParamLine(string $line): array
    {
        // The " * " prefix is 3 chars. Total line = " * " + content = 3 +
        // content.
        // We want total line width <= 120, so content width <= 117.
        $maxContentWidth = 117;

        // Continuation indent: 8 spaces (aligns under type after "@param ")
        $continuationIndent = '       ';  // 7 spaces

        if (strlen($line) <= $maxContentWidth) {
            return [$line];
        }

        $lines = [];
        $remaining = $line;
        $isFirst = true;

        while (strlen($remaining) > $maxContentWidth) {
            $width = $isFirst ? $maxContentWidth : $maxContentWidth - strlen(
                $continuationIndent
            );
            $text = $isFirst ? $remaining : $remaining;
            $maxLen = $isFirst ? $maxContentWidth : $maxContentWidth;

            // Find the last space within the allowed width
            $breakAt = strrpos(substr($remaining, 0, $maxLen + 1), ' ', 0);

            if ($breakAt === false || $breakAt === 0) {
                // No space found - force break at max width
                $breakAt = $maxLen;
            }

            $chunk = substr($remaining, 0, $breakAt);
            $remaining = ltrim(substr($remaining, $breakAt));

            if ($isFirst) {
                $lines[] = $chunk;
                $isFirst = false;
            } else {
                $lines[] = $continuationIndent . $chunk;
            }
        }

        // Add the remaining text
        if ($remaining !== '') {
            if ($isFirst) {
                $lines[] = $remaining;
            } else {
                $lines[] = $continuationIndent . $remaining;
            }
        }

        return $lines;
    }

    private function extractShortName(string $fqcn): string
    {
        $lastSlash = strrpos($fqcn, '\\');
        if ($lastSlash === false) {
            return $fqcn;
        }
        return substr($fqcn, $lastSlash + 1);
    }

    private function resolveErrorParentClass(): string
    {
        foreach ($this->model->getServiceShapes() as $shapeId => $_) {
            if (!\str_starts_with($shapeId, 'smithy.api#')) {
                return $this->settings->resolveServiceExceptionClass();
            }
        }

        if ($this->settings->serviceExceptionClass !== null) {
            return $this->settings->resolveServiceExceptionClass();
        }

        return $this->settings->baseServiceException
            ?? 'Smithy\\Core\\Exception\\ApiException';
    }

    private function errorParentReference(
        PhpCodeWriter $writer,
        string $className
    ): string {
        if (\strcasecmp(
            \ltrim($this->errorParentClass, '\\'),
            \ltrim($this->settings->resolveServiceExceptionClass(), '\\')
            ) === 0
        ) {
            return $writer->addImportReference(
                $this->errorParentClass,
                'ServiceException',
                [$className, 'AbstractDocument', 'Schemas']
            );
        }

        return '\\' . \ltrim($this->errorParentClass, '\\');
    }

    private function smithyTypeToEnum(string $type): string
    {
        return match ($type) {
            'structure' => 'ShapeType::Structure',
            'union' => 'ShapeType::Union',
            'enum' => 'ShapeType::Enum',
            'intEnum' => 'ShapeType::IntEnum',
            'list' => 'ShapeType::List_',
            'map' => 'ShapeType::Map',
            'string' => 'ShapeType::String_',
            'integer' => 'ShapeType::Integer',
            'long' => 'ShapeType::Long',
            'short' => 'ShapeType::Short',
            'byte' => 'ShapeType::Byte',
            'float' => 'ShapeType::Float_',
            'double' => 'ShapeType::Double',
            'boolean' => 'ShapeType::Boolean',
            'timestamp' => 'ShapeType::Timestamp',
            'blob' => 'ShapeType::Blob',
            'bigInteger' => 'ShapeType::BigInteger',
            'bigDecimal' => 'ShapeType::BigDecimal',
            'document' => 'ShapeType::Document',
            default => 'ShapeType::String_'
        };
    }

    /**
     * Build listMember/mapKey/mapValue parameters for list and map types.
     *
     * @param array<string, true> $visited Cycle detection set of already-expanded shape IDs
     */
    private function buildCollectionParams(
        string $targetId,
        string $targetType,
        array $visited = []
    ): string
    {
        // Cycle detection: if we've already expanded this shape, stop recursing
        if (isset($visited[$targetId])) {
            return '';
        }
        $visited[$targetId] = true;

        $targetShape = $this->model->getShape($targetId);
        if ($targetShape === null) {
            return '';
        }

        if ($targetType === 'list') {
            $memberTarget =
                $targetShape['member']['target'] ?? 'smithy.api#String';
            $memberType = $this->model->getShapeType($memberTarget) ?? 'string';
            $memberTypeEnum = $this->smithyTypeToEnum($memberType);
            // Check for xmlName on the list member
            $memberTraits = $targetShape['member']['traits'] ?? [];
            $listMemberTraits = $this->buildMemberTraits($memberTraits);
            $listMemberTraitsStr = $this->renderTraitsArray($listMemberTraits);
            $memberMembers = $this->buildInlineMembers(
                $memberTarget,
                $memberType,
                $visited
            );

            return ", listMember: new Schema(id: '{$memberTarget}', "
                . "type: {$memberTypeEnum}"
                . "{$memberMembers}{$listMemberTraitsStr})";
        }

        if ($targetType === 'map') {
            $keyTarget = $targetShape['key']['target'] ?? 'smithy.api#String';
            $keyType = $this->model->getShapeType($keyTarget) ?? 'string';
            $keyTypeEnum = $this->smithyTypeToEnum($keyType);

            $valueTarget =
                $targetShape['value']['target'] ?? 'smithy.api#String';
            $valueType = $this->model->getShapeType($valueTarget) ?? 'string';
            $valueTypeEnum = $this->smithyTypeToEnum($valueType);

            // Check for xmlName on key/value
            $keyTraits = $targetShape['key']['traits'] ?? [];
            $valueTraits = $targetShape['value']['traits'] ?? [];
            $keyTraitsStr = $this->renderTraitsArray(
                $this->buildMemberTraits($keyTraits)
            );
            $valueTraitsStr = $this->renderTraitsArray(
                $this->buildMemberTraits($valueTraits)
            );
            $valueMembers = $this->buildInlineMembers(
                $valueTarget,
                $valueType,
                $visited
            );
            // Recursively include collection params for nested map/list values
            $valueCollectionParams = $this->buildCollectionParams(
                $valueTarget,
                $valueType,
                $visited
            );

            return ", mapKey: new Schema(id: '{$keyTarget}', "
                . "type: {$keyTypeEnum}{$keyTraitsStr}), "
                . "mapValue: new Schema(id: '{$valueTarget}', "
                . "type: {$valueTypeEnum}"
                . "{$valueMembers}{$valueCollectionParams}{$valueTraitsStr})";
        }

        return '';
    }

    /**
     * Build inline members for a structure/union target in a collection schema.
     * This allows the deserializer to read nested struct members without
     * needing to look up the class.
     *
     * @param array<string, true> $visited Cycle detection set of already-expanded shape IDs
     */
    private function buildInlineMembers(
        string $targetId,
        string $targetType,
        array $visited = []
    ): string
    {
        if ($targetType !== 'structure' && $targetType !== 'union') {
            return '';
        }

        // Cycle detection: if we've already expanded this shape, stop recursing
        // The runtime deserializer's resolveSchemaFromId() will handle
        // resolution
        if (isset($visited[$targetId])) {
            return '';
        }
        $visited[$targetId] = true;

        $members = $targetType === 'structure'
            ? $this->getMembersIndexed($targetId)
            : $this->model->getMembers($targetId);
        if (empty($members)) {
            return '';
        }

        $parts = [];
        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $memTargetId = $memberDef['target'] ?? 'smithy.api#String';
            $memTargetType =
                $this->model->getShapeType($memTargetId) ?? 'string';
            $memTypeEnum = $this->smithyTypeToEnum($memTargetType);
            $memTraits = $this->buildMemberTraits($memberDef['traits'] ?? []);
            $memTraitsStr = $this->renderTraitsArray($memTraits);
            $memCollectionParams = $this->buildCollectionParams(
                $memTargetId,
                $memTargetType,
                $visited
            );
            // For all struct/union member stubs, emit targetClass for runtime
            // resolution.
            // These members don't get their own members inlined at this depth,
            // so the deserializer needs targetClass to resolve the full schema.
            // Use FQCN with leading backslash to avoid namespace resolution
            // issues.
            $targetClassStr = '';
            if ($memTargetType === 'structure' || $memTargetType === 'union') {
                $targetFqcn = $this->symbolProvider->toClassName($memTargetId);
                $targetClassStr = ", targetClass: \\{$targetFqcn}::class";
            }
            $parts[] = "new Schema(id: '{$memTargetId}', "
                . "type: {$memTypeEnum}, "
                . "memberName: '{$memberName}', "
                . "memberIndex: {$index}"
                . "{$memCollectionParams}{$memTraitsStr}{$targetClassStr})";
            $index++;
        }

        return ', members: [' . implode(', ', $parts) . ']';
    }

    /**
     * Emit the universal `serialize($s, $idx)` entry point declared by
     * {@see SerializableShapeInterface}. The body dispatches into the
     * codec's `serializeStruct()` with this struct's root schema.
     *
     * @return void
     */
    private function writeSerializeMethod(PhpCodeWriter $writer): void
    {
        $writer->writeDocBlock([
            'Serialize the shape to the desired wire format.',
            '',
            '@return void'
        ]);
        $writer->writeLine(
            'public function serialize(ShapeSerializerInterface $serializer, '
            . 'int $idx): void'
        );
        $writer->openBlock('{');
        $writer->writeLine(
            '$serializer->serializeStruct(self::schema(), $this, $idx);'
        );
        $writer->closeBlock();
    }

    /**
     * Generate the serializeMembers() method.
     *
     * Always emits generic write*() calls for all members. The serializer
     * (HttpBindingSerializer or codec) handles routing based on schema traits.
     * This makes generated shapes protocol-agnostic.
     *
     * @return void
     */
    private function writeSerializeMembersMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        // Use constructor order so member indices match constructor arg
        // positions
        $members = $this->getMembersIndexed($shapeId);

        $writer->writeDocBlock([
            'Serialize the shape members to the desired wire format.',
            '',
            '@return void'
        ]);
        if (empty($members)) {
            $writer->writeLine(
                'public function serializeMembers('
                . 'ShapeSerializerInterface $serializer): void {}'
            );
            return;
        }

        $this->writeSerializeMembersGeneric($writer, $shapeId, $members);
    }

    /**
     * Emit serializeMembers() with inline HTTP binding serialization.
     * HTTP-bound members are written directly to serializer properties.
     * Body members use write*() forwarding to codec.
     */
    private function writeSerializeMembersWithHttpBindings(
        PhpCodeWriter $writer,
        string $shapeId,
        array $members
    ): void
    {
        $writer->writeLine(
            'public function serializeMembers('
            . 'ShapeSerializerInterface $serializer): void'
        );
        $writer->openBlock('{');
        $writer->writeLine();

        // Separate members by binding type.
        $httpMembers = [];
        $bodyMembers = [];
        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $memberTraits = $memberDef['traits'] ?? [];
            $binding = $this->resolveHttpBinding($memberTraits);
            if ($binding !== null) {
                $httpMembers[$index] = [
                    $memberName,
                    $memberDef,
                    $binding[0],
                    $binding[1]
                ];
            } else {
                $bodyMembers[$index] = [$memberName, $memberDef];
            }
            $index++;
        }

        // Emit inline HTTP member serialization (no schema access needed)
        foreach ($httpMembers as $idx => [
            $memberName,
            $memberDef,
            $bindingType,
            $bindingValue
        ]) {
            $this->writeInlineHttpMember(
                $writer,
                $shapeId,
                $memberName,
                $memberDef,
                $idx,
                $bindingType,
                $bindingValue
            );
        }

        // Emit body members via write*() calls (forwarded to codec)
        // Each body member accesses schema inline inside isset check
        if (!empty($bodyMembers)) {
            $writer->writeLine();
            $writer->writeLine(
                '// Body members - forwarded to codec serializer'
            );

            // Write attribute members first (XML ordering)
            $attrBody = [];
            $elemBody = [];
            foreach ($bodyMembers as $idx => [$memberName, $memberDef]) {
                $memberTraits = $memberDef['traits'] ?? [];
                if (isset($memberTraits['smithy.api#xmlAttribute'])) {
                    $attrBody[$idx] = [$memberName, $memberDef];
                } else {
                    $elemBody[$idx] = [$memberName, $memberDef];
                }
            }
            foreach ($attrBody as $idx => [$memberName, $memberDef]) {
                $this->writeBodyMemberCall(
                    $writer,
                    $shapeId,
                    $memberName,
                    $memberDef,
                    $idx
                );
            }
            foreach ($elemBody as $idx => [$memberName, $memberDef]) {
                $this->writeBodyMemberCall(
                    $writer,
                    $shapeId,
                    $memberName,
                    $memberDef,
                    $idx
                );
            }
        }

        $writer->closeBlock();
    }

    /**
     * Emit serializeMembers() using Schemas::$ClassName_MemberName references.
     * Ensures schema is initialized via Schemas init method, then references
     * member schemas directly from the centralized Schemas class.
     */
    private function writeSerializeMembersGeneric(
        PhpCodeWriter $writer,
        string $shapeId,
        array $members
    ): void
    {
        $attrMembers = [];
        $elemMembers = [];
        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $memberTraits = $memberDef['traits'] ?? [];
            if (isset($memberTraits['smithy.api#xmlAttribute'])) {
                $attrMembers[$index] = [$memberName, $memberDef];
            } else {
                $elemMembers[$index] = [$memberName, $memberDef];
            }
            $index++;
        }

        $writer->writeLine(
            'public function serializeMembers('
            . 'ShapeSerializerInterface $serializer): void'
        );
        $writer->openBlock('{');

        foreach ($attrMembers as $idx => [$memberName, $memberDef]) {
            $this->writeSerializeMemberCallSchemas(
                $writer,
                $shapeId,
                $memberName,
                $memberDef,
                $idx
            );
        }
        foreach ($elemMembers as $idx => [$memberName, $memberDef]) {
            $this->writeSerializeMemberCallSchemas(
                $writer,
                $shapeId,
                $memberName,
                $memberDef,
                $idx
            );
        }

        $writer->closeBlock();
    }

    /**
     * Resolve HTTP binding type and value for a member.
     * Returns [bindingType, bindingValue] or null if not HTTP-bound.
     */
    private function resolveHttpBinding(array $memberTraits): ?array
    {
        if (isset($memberTraits['smithy.api#httpHeader'])) {
            return [
                'header',
                strtolower($memberTraits['smithy.api#httpHeader'])
            ];
        }
        if (isset($memberTraits['smithy.api#httpQuery'])) {
            return ['query', $memberTraits['smithy.api#httpQuery']];
        }
        if (isset($memberTraits['smithy.api#httpLabel'])) {
            return ['label', null];
        }
        if (isset($memberTraits['smithy.api#httpPayload'])) {
            return ['payload', null];
        }
        if (isset($memberTraits['smithy.api#httpPrefixHeaders'])) {
            return [
                'prefixHeaders',
                $memberTraits['smithy.api#httpPrefixHeaders']
            ];
        }
        if (isset($memberTraits['smithy.api#httpQueryParams'])) {
            return ['queryParams', null];
        }
        return null;
    }

    /**
     * Emit inline serialization for a single HTTP-bound member.
     */
    private function writeInlineHttpMember(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        array $memberDef,
        int $index,
        string $bindingType,
        ?string $bindingValue
    ): void
    {
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );
        $targetId = $memberDef['target'] ?? 'smithy.api#String';
        $targetType = $this->model->getShapeType($targetId) ?? 'string';
        $memberTraits = $memberDef['traits'] ?? [];

        $writer->writeLine("if (isset(\$this->{$propertyName})) {");
        $writer->pushIndent();

        match ($bindingType) {
            'header' => $this->writeInlineHeader(
                $writer,
                $propertyName,
                $targetType,
                $bindingValue,
                $memberTraits,
                $targetId,
                $index
            ),
            'query' => $this->writeInlineQuery(
                $writer,
                $propertyName,
                $targetType,
                $bindingValue,
                $memberTraits,
                $targetId,
                $index
            ),
            'label' => $this->writeInlineLabel(
                $writer,
                $propertyName,
                $targetType,
                $memberName,
                $memberTraits,
                $targetId,
                $index
            ),
            'payload' => $this->writeInlinePayload(
                $writer,
                $propertyName,
                $targetType,
                $index
            ),
            'prefixHeaders' => $this->writeInlinePrefixHeaders(
                $writer,
                $propertyName,
                $bindingValue
            ),
            'queryParams' => $this->writeInlineQueryParams(
                $writer,
                $propertyName
            )
        };

        $writer->popIndent();
        $writer->writeLine('}');
    }

    private function writeInlineHeader(
        PhpCodeWriter $writer,
        string $prop,
        string $type,
        string $headerName,
        array $traits,
        string $targetId,
        int $idx
    ): void
    {
        $target = "\$serializer->headers['{$headerName}']";
        $source = "\$this->{$prop}";

        match ($type) {
            'string' => $writer->writeLine("{$target} = {$source};"),
            'integer', 'long', 'short', 'byte' => $writer->writeLine(
                "{$target} = (string) {$source};"
            ),
            'boolean' => $writer->writeLine(
                "{$target} = {$source} ? 'true' : 'false';"
            ),
            'float', 'double' => $writer->writeLine(
                "{$target} = \$serializer->formatFloat({$source});"
            ),
            'timestamp' => $this->writeInlineTimestamp(
                $writer,
                $prop,
                $headerName,
                'headers',
                $traits,
                $targetId,
                1
            ),
            'enum' => $writer->writeLine("{$target} = {$source}->value;"),
            'intEnum' => $writer->writeLine(
                "{$target} = (string) {$source}->value;"
            ),
            'list' => $this->writeInlineListHeader(
                $writer,
                $prop,
                $headerName,
                $targetId,
                $idx
            ),
            default => $writer->writeLine("{$target} = (string) {$source};")
        };
    }

    private function writeInlineQuery(
        PhpCodeWriter $writer,
        string $prop,
        string $type,
        string $queryName,
        array $traits,
        string $targetId,
        int $idx
    ): void
    {
        $source = "\$this->{$prop}";

        match ($type) {
            'string' => $writer->writeLine(
                "\$serializer->writeQueryPair('{$queryName}', {$source});"
            ),
            'integer', 'long', 'short', 'byte' => $writer->writeLine(
                "\$serializer->writeQueryPair("
                . "'{$queryName}', (string) {$source});"
            ),
            'boolean' => $writer->writeLine(
                "\$serializer->writeQueryPair("
                . "'{$queryName}', {$source} ? 'true' : 'false');"
            ),
            'float', 'double' => $writer->writeLine(
                "\$serializer->writeQueryPair("
                . "'{$queryName}', \$serializer->formatFloat({$source}));"
            ),
            'timestamp' => $this->writeInlineTimestamp(
                $writer,
                $prop,
                $queryName,
                'query',
                $traits,
                $targetId,
                0
            ),
            'enum' => $writer->writeLine(
                "\$serializer->writeQueryPair("
                . "'{$queryName}', {$source}->value);"
            ),
            'intEnum' => $writer->writeLine(
                "\$serializer->writeQueryPair("
                . "'{$queryName}', (string) {$source}->value);"
            ),
            'list' => $this->writeInlineListQuery(
                $writer,
                $prop,
                $queryName,
                $targetId
            ),
            default => $writer->writeLine(
                "\$serializer->writeQueryPair('{$queryName}', "
                . "(string) {$source});"
            )
        };
    }

    private function writeInlineLabel(
        PhpCodeWriter $writer,
        string $prop,
        string $type,
        string $memberName,
        array $traits,
        string $targetId,
        int $idx
    ): void
    {
        $target = "\$serializer->labels['{$memberName}']";
        $source = "\$this->{$prop}";

        match ($type) {
            'string' => $writer->writeLine("{$target} = {$source};"),
            'integer', 'long', 'short', 'byte' => $writer->writeLine(
                "{$target} = (string) {$source};"
            ),
            'boolean' => $writer->writeLine(
                "{$target} = {$source} ? 'true' : 'false';"
            ),
            'float', 'double' => $writer->writeLine(
                "{$target} = \$serializer->formatFloat({$source});"
            ),
            'timestamp' => $this->writeInlineTimestamp(
                $writer,
                $prop,
                $memberName,
                'labels',
                $traits,
                $targetId,
                0
            ),
            'enum' => $writer->writeLine("{$target} = {$source}->value;"),
            'intEnum' => $writer->writeLine(
                "{$target} = (string) {$source}->value;"
            ),
            default => $writer->writeLine("{$target} = (string) {$source};")
        };
    }

    private function writeInlinePayload(
        PhpCodeWriter $writer,
        string $prop,
        string $type,
        int $idx
    ): void
    {
        // All payloads: set raw value. Protocol resolves schema from
        // bindingInfo.
        $writer->writeLine("\$serializer->payload = \$this->{$prop};");
    }

    private function writeInlinePrefixHeaders(
        PhpCodeWriter $writer,
        string $prop,
        string $prefix
    ): void
    {
        $writer->writeLine(
            "\$serializer->serializePrefixHeaders("
            . "'{$prefix}', \$this->{$prop});"
        );
    }

    private function writeInlineQueryParams(PhpCodeWriter $writer, string $prop): void
    {
        $writer->writeLine(
            "\$serializer->serializeQueryParams(\$this->{$prop});"
        );
    }

    private function writeInlineTimestamp(
        PhpCodeWriter $writer,
        string $prop,
        string $name,
        string $target,
        array $traits,
        string $targetId,
        int $defaultFormat
    ): void
    {
        // Resolve format from member or target traits
        $format = $traits['smithy.api#timestampFormat'] ?? null;
        if ($format === null) {
            $format = $this->model->getTrait(
                $targetId,
                'smithy.api#timestampFormat'
            );
        }

        $formatInt = $format !== null ? match ($format) {
            'http-date' => 1,
            'epoch-seconds' => 2,
            'date-time' => 0,
            default => $defaultFormat,
        } : $defaultFormat;

        $source = "\$this->{$prop}";
        if ($target === 'headers') {
            $writer->writeLine(
                "\$serializer->headers['{$name}'] = "
                . "\$serializer->formatTimestamp({$source}, {$formatInt});"
            );
        } elseif ($target === 'query') {
            $writer->writeLine(
                "\$serializer->writeQueryPair('{$name}', "
                . "\$serializer->formatTimestamp({$source}, {$formatInt}));"
            );
        } else {
            $writer->writeLine(
                "\$serializer->labels['{$name}'] = "
                . "\$serializer->formatTimestamp({$source}, {$formatInt});"
            );
        }
    }

    private function writeInlineListHeader(
        PhpCodeWriter $writer,
        string $prop,
        string $headerName,
        string $targetId,
        int $idx
    ): void
    {
        // For list headers, join values with ', '
        $targetShape = $this->model->getShape($targetId);
        $itemTarget = $targetShape['member']['target'] ?? 'smithy.api#String';
        $itemType = $this->model->getShapeType($itemTarget) ?? 'string';
        $target = "\$serializer->headers['{$headerName}']";
        $source = "\$this->{$prop}";

        if ($itemType === 'string') {
            $writer->writeLine("{$target} = implode(', ', {$source});");
        } elseif ($itemType === 'boolean') {
            $writer->writeLine(
                "{$target} = implode(', ', array_map("
                . "fn(\$v) => \$v ? 'true' : 'false', {$source}));"
            );
        } elseif ($itemType === 'timestamp') {
            $writer->writeLine(
                "{$target} = implode(', ', array_map("
                . "fn(\$v) => \$serializer->formatTimestamp(\$v, 1), "
                . "{$source}));"
            );
        } elseif (in_array(
            $itemType,
            ['integer', 'long', 'short', 'byte'],
            true
        )) {
            $writer->writeLine(
                "{$target} = implode(', ', "
                . "array_map(fn(\$v) => (string) \$v, {$source}));"
            );
        } elseif (in_array($itemType, ['enum', 'intEnum'], true)) {
            $writer->writeLine(
                "{$target} = implode(', ', "
                . "array_map(fn(\$v) => (string) \$v->value, {$source}));"
            );
        } else {
            $writer->writeLine(
                "{$target} = implode(', ', "
                . "array_map(fn(\$v) => (string) \$v, {$source}));"
            );
        }
    }

    private function writeInlineListQuery(
        PhpCodeWriter $writer,
        string $prop,
        string $queryName,
        string $targetId
    ): void
    {
        // For list queries, detect item type to handle non-string items
        // correctly
        $targetShape = $this->model->getShape($targetId);
        $itemTarget = $targetShape['member']['target'] ?? 'smithy.api#String';
        $itemType = $this->model->getShapeType($itemTarget) ?? 'string';
        $source = "\$this->{$prop}";

        if (in_array($itemType, ['enum', 'intEnum'], true)) {
            $writer->writeLine(
                "\$serializer->serializeListQuery('{$queryName}', "
                . "array_map(fn(\$v) => (string) \$v->value, {$source}));"
            );
        } elseif ($itemType === 'boolean') {
            $writer->writeLine(
                "\$serializer->serializeListQuery('{$queryName}', "
                . "array_map(fn(\$v) => \$v ? 'true' : 'false', {$source}));"
            );
        } elseif ($itemType === 'timestamp') {
            $writer->writeLine(
                "\$serializer->serializeListQuery('{$queryName}', "
                . "array_map(fn(\$v) => \$serializer->formatTimestamp(\$v, 0), "
                . "{$source}));"
            );
        } else {
            $writer->writeLine(
                "\$serializer->serializeListQuery('{$queryName}', {$source});"
            );
        }
    }

    /**
     * Emit a body member's serialization call with inline schema access.
     * Schema is accessed inside the isset check so no schema call happens if member is null.
     */
    private function writeBodyMemberCall(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        array $memberDef,
        int $index
    ): void
    {
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );
        $targetId = $memberDef['target'] ?? 'smithy.api#String';
        $targetType = $this->model->getShapeType($targetId) ?? 'string';
        $memberTraits = $memberDef['traits'] ?? [];

        $schemaRef = "self::schema()->getMemberByIndex({$index})";

        // Determine the write* method to call based on the target type
        $writeCall = match ($targetType) {
            'boolean' => $this->buildSerializerCall(
                'serializeBoolean',
                $schemaRef,
                $propertyName
            ),
            'integer', 'long', 'short', 'byte' => $this->buildSerializerCall(
                'serializeInteger',
                $schemaRef,
                $propertyName
            ),
            'float', 'double' => $this->buildSerializerCall(
                'serializeFloat',
                $schemaRef,
                $propertyName
            ),
            'bigInteger' => $this->buildSerializerCall(
                'serializeBigInteger',
                $schemaRef,
                $propertyName
            ),
            'bigDecimal' => $this->buildSerializerCall(
                'serializeBigDecimal',
                $schemaRef,
                $propertyName
            ),
            'string' => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName
            ),
            'enum' => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName,
                value: "\$this->{$propertyName}->value"
            ),
            'intEnum' => $this->buildSerializerCall(
                'serializeInteger',
                $schemaRef,
                $propertyName,
                value: "\$this->{$propertyName}->value"
            ),
            'blob' => $this->buildSerializerCall(
                'serializeBlob',
                $schemaRef,
                $propertyName
            ),
            'timestamp' => $this->buildTimestampWriteCallInline(
                $index,
                $propertyName
            ),
            'document' => $this->buildSerializerCall(
                'serializeDocument',
                $schemaRef,
                $propertyName,
                $index
            ),
            'list' => $this->buildListWriteCallInline($index, $propertyName),
            'map' => $this->buildSerializerCall(
                'serializeMap',
                $schemaRef,
                $propertyName
            ),
            'structure' => $this->buildSerializerCall(
                'serializeStruct',
                $schemaRef,
                $propertyName
            ),
            'union' => $this->buildSerializerCall(
                'serializeUnion',
                $schemaRef,
                $propertyName
            ),
            default => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName,
                value: "(string) \$this->{$propertyName}"
            )
        };

        $writer->writeLine("if (isset(\$this->{$propertyName})) {");
        $writer->pushIndent();
        $writer->writeLine($writeCall);
        $writer->popIndent();
        $writer->writeLine('}');
    }

    private function buildTimestampWriteCallInline(
        int $index,
        string $propertyName
    ): string
    {
        $schemaRef = "self::schema()->getMemberByIndex({$index})";
        return "\$serializer->serializeTimestamp("
            . "{$schemaRef}, \$this->{$propertyName}, 0);";
    }

    /**
     * Build the writeList() call for the inline body member path.
     */
    private function buildListWriteCallInline(int $index, string $propertyName): string
    {
        $schemaRef = "self::schema()->getMemberByIndex({$index})";
        return "\$serializer->serializeList("
            . "{$schemaRef}, \$this->{$propertyName}, 0);";
    }

    /**
     * Emit a single member's serialization call in serializeMembers() (generic path with $m array).
     */
    private function writeSerializeMemberCall(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        array $memberDef,
        int $index
    ): void
    {
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );
        $targetId = $memberDef['target'] ?? 'smithy.api#String';
        $targetType = $this->model->getShapeType($targetId) ?? 'string';
        $memberTraits = $memberDef['traits'] ?? [];

        // Determine the write* method to call based on the target type
        $writeCall = match ($targetType) {
            'boolean' => $this->buildSerializerCall(
                'serializeBoolean',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'integer', 'long', 'short', 'byte' => $this->buildSerializerCall(
                'serializeInteger',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'float', 'double' => $this->buildSerializerCall(
                'serializeFloat',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'bigInteger' => $this->buildSerializerCall(
                'serializeBigInteger',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'bigDecimal' => $this->buildSerializerCall(
                'serializeBigDecimal',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'string' => $this->buildSerializerCall(
                'serializeString',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'enum' => $this->buildSerializerCall(
                'serializeString',
                "\$m[{$index}]",
                $propertyName,
                $index,
                "\$this->{$propertyName}->value"
            ),
            'intEnum' => $this->buildSerializerCall(
                'serializeInteger',
                "\$m[{$index}]",
                $propertyName,
                $index,
                "\$this->{$propertyName}->value"
            ),
            'blob' => $this->buildSerializerCall(
                'serializeBlob',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'timestamp' => $this->buildTimestampWriteCall(
                $index,
                $propertyName
            ),
            'document' => $this->buildSerializerCall(
                'serializeDocument',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'list' => $this->buildListWriteCall($index, $propertyName),
            'map' => $this->buildSerializerCall(
                'serializeMap',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'structure' => $this->buildSerializerCall(
                'serializeStruct',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            'union' => $this->buildSerializerCall(
                'serializeUnion',
                "\$m[{$index}]",
                $propertyName,
                $index
            ),
            default => $this->buildSerializerCall(
                'serializeString',
                "\$m[{$index}]",
                $propertyName,
                $index,
                "(string) \$this->{$propertyName}"
            )
        };

        $writer->writeLine("if (isset(\$this->{$propertyName})) {");
        $writer->pushIndent();
        $writer->writeLine($writeCall);
        $writer->popIndent();
        $writer->writeLine('}');
    }

    private function buildTimestampWriteCall(
        int $index,
        string $propertyName
    ): string
    {
        return $this->buildSerializerCall(
            'serializeTimestamp',
            "\$m[{$index}]",
            $propertyName,
            $index
        );
    }

    /**
     * Build the writeList() call.
     */
    private function buildListWriteCall(int $index, string $propertyName): string
    {
        return "\$serializer->serializeList("
            . "\$m[{$index}], \$this->{$propertyName}, {$index});";
    }

    /**
     * Generate the deserializeMembers() method for output/shared/error shapes.
     *
     * For shapes with HTTP response bindings: delegates to $deserializer->readMembers()
     * which handles all HTTP binding dispatch + body delegation using precomputed data.
     * For shapes without HTTP bindings: delegates entirely to $deserializer->readStruct().
     */
    private function writeDeserializeMembersMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        bool $hasHttpResponseBindings
    ): void
    {
        $writer->writeLine(
            'public static function deserializeMembers('
            . 'ShapeDeserializerInterface $deserializer, '
            . 'array &$fields): void'
        );
        $writer->openBlock('{');

        if (!$hasHttpResponseBindings) {
            // No HTTP bindings - delegate entirely to the deserializer (codec
            // or routing)
            $writer->writeLine(
                '$deserializer->readStruct(self::schema(), $fields);'
            );
        } else {
            // HTTP response bindings - delegate to readMembers() which handles
            // all routing
            $writer->writeLine(
                '$deserializer->readMembers(self::schema(), $fields);'
            );
        }

        $writer->closeBlock();
    }

    private function writeGetMemberValueMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        $members = $this->getMembersIndexed($shapeId);

        $writer->writeDocBlock(['Get a modeled member value.']);
        $writer->writeLine('public function getMemberValue(Schema $member): mixed');
        $writer->openBlock('{');
        $writer->writeLine('return match ($member->memberIndex) {');
        $writer->pushIndent();

        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $propertyName = $this->toMemberStoragePropertyName(
                $shapeId,
                $memberName
            );
            $optionalEnum = $this->isScalarEnumMember($shapeId, $memberName)
                && !$this->model->isMemberRequired($shapeId, $memberName)
                && !$this->model->isMemberDefaulted($shapeId, $memberName);
            $fallback = $optionalEnum ? ' ?? null' : '';
            $writer->writeLine(
                "{$index} => \$this->{$propertyName}{$fallback},"
            );
            $index++;
        }

        $writer->writeLine('default => null,');
        $writer->popIndent();
        $writer->writeLine('};');
        $writer->closeBlock();
    }

    private function writeFromFieldsMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        string $className
    ): void
    {
        // The deserializer is responsible for producing a dense, slot-typed
        // `$fields` array: each slot already carries the value the matching
        // constructor parameter expects (hydrated structs/unions/enums,
        // lists/maps of structs already hydrated, defaulted members already
        // filled in). `fromFields` collapses to a single positional spread
        // so the JIT can inline this into the deserializer's hot loop.
        //
        // Empty struct shapes (no members) still need the explicit `new
        // self()` form - a spread over an empty array would be a no-op call
        // but is uglier and yields no perf benefit.
        $members = $this->getMembersIndexed($shapeId);

        $writer->writeDocBlock([
            'Create an instance from a positional field array.',
            '',
            'The deserializer pre-hydrates every slot to the type the matching',
            'constructor parameter accepts, so this is a one-line positional',
            'spread with no per-slot fallback logic.',
            '',
            '@param array $fields Positional values indexed by memberIndex',
            '@return self'
        ]);
        $writer->writeLine('public static function fromFields(array $fields): static');
        $writer->openBlock('{');

        if (empty($members)) {
            $writer->writeLine('return new self();');
        } else {
            $writer->writeLine('return new self(...$fields);');
        }

        $writer->closeBlock();
    }



    /**
     * Emit a single member's serialization call using self::$S_MEMBER_NAME.
     * References the local private static member schema property.
     */
    private function writeSerializeMemberCallSchemas(
        PhpCodeWriter $writer,
        string $shapeId,
        string $memberName,
        array $memberDef,
        int $index
    ): void
    {
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );
        $targetId = $memberDef['target'] ?? 'smithy.api#String';
        $targetType = $this->model->getShapeType($targetId) ?? 'string';
        $memberTraits = $memberDef['traits'] ?? [];

        // Build self::$S_MEMBER_NAME reference
        $constName = $this->toSchemaConstName($memberName);
        $schemaRef = "self::\${$constName}";

        // Determine the write* method to call based on the target type
        $writeCall = match ($targetType) {
            'boolean' => $this->buildSerializerCall(
                'serializeBoolean',
                $schemaRef,
                $propertyName,
                $index
            ),
            'integer', 'long', 'short', 'byte' => $this->buildSerializerCall(
                'serializeInteger',
                $schemaRef,
                $propertyName,
                $index
            ),
            'float', 'double' => $this->buildSerializerCall(
                'serializeFloat',
                $schemaRef,
                $propertyName,
                $index
            ),
            'bigInteger' => $this->buildSerializerCall(
                'serializeBigInteger',
                $schemaRef,
                $propertyName,
                $index
            ),
            'bigDecimal' => $this->buildSerializerCall(
                'serializeBigDecimal',
                $schemaRef,
                $propertyName,
                $index
            ),
            'string' => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName,
                $index
            ),
            'enum' => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName,
                $index,
                "\$this->{$propertyName}->value"
            ),
            'intEnum' => $this->buildSerializerCall(
                'serializeInteger',
                $schemaRef,
                $propertyName,
                $index,
                "\$this->{$propertyName}->value"
            ),
            'blob' => $this->buildSerializerCall(
                'serializeBlob',
                $schemaRef,
                $propertyName,
                $index
            ),
            'timestamp' => $this->buildTimestampWriteCallWithSchema(
                $schemaRef,
                $index,
                $propertyName
            ),
            'document' => $this->buildSerializerCall(
                'serializeDocument',
                $schemaRef,
                $propertyName,
                $index
            ),
            'list' => $this->buildListWriteCallWithSchema(
                $schemaRef,
                $index,
                $propertyName
            ),
            'map' => $this->buildSerializerCall(
                'serializeMap',
                $schemaRef,
                $propertyName,
                $index
            ),
            'structure' => $this->buildSerializerCall(
                'serializeStruct',
                $schemaRef,
                $propertyName,
                $index
            ),
            'union' => $this->buildSerializerCall(
                'serializeUnion',
                $schemaRef,
                $propertyName,
                $index
            ),
            default => $this->buildSerializerCall(
                'serializeString',
                $schemaRef,
                $propertyName,
                $index,
                "(string) \$this->{$propertyName}"
            )
        };

        $writer->writeLine("if (isset(\$this->{$propertyName})) {");
        $writer->pushIndent();
        $writer->writeLine($writeCall);
        $writer->popIndent();
        $writer->writeLine('}');
    }

    private function buildTimestampWriteCallWithSchema(
        string $schemaRef,
        int $index,
        string $propertyName
    ): string
    {
        return $this->buildSerializerCall(
            'serializeTimestamp',
            $schemaRef,
            $propertyName,
            $index
        );
    }

    private function buildListWriteCallWithSchema(
        string $schemaRef,
        int $index,
        string $propertyName
    ): string
    {
        return "\$serializer->serializeList("
            . "{$schemaRef}, \$this->{$propertyName}, {$index});";
    }

    private function buildSerializerCall(
        string $method,
        string $schemaRef,
        string $propertyName,
        ?int $index = null,
        ?string $value = null
    ): string
    {
        $value ??= "\$this->{$propertyName}";
        $args = [$schemaRef, $value];
        if ($index !== null) {
            $args[] = (string) $index;
        }

        return "\$serializer->{$method}(" . \implode(', ', $args) . ');';
    }

    /**
     * Convert a Smithy member name (e.g., "CopySourceIfModifiedSince") to SCHEMA_UPPER_SNAKE_CASE
     * for use as a static schema property name (e.g., "SCHEMA_COPY_SOURCE_IF_MODIFIED_SINCE").
     */
    private function toSchemaConstName(string $memberName): string
    {
        // Insert underscore before each uppercase letter that follows a
        // lowercase/digit
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $memberName);
        // Handle consecutive uppercase (e.g., "SSECustomerKey" ->
        // "SSE_Customer_Key")
        $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);
        return 'SCHEMA_' . strtoupper($snake);
    }

    private function isInheritedExceptionProperty(
        string $shapeId,
        string $memberName
    ): bool
    {
        if (!$this->model->hasTrait($shapeId, 'smithy.api#error')) {
            return false;
        }

        $propertyName = $this->symbolProvider->toPropertyName($memberName);

        return isset(self::INHERITED_EXCEPTION_PROPERTIES[$propertyName]);
    }

    private function toMemberStoragePropertyName(
        string $shapeId,
        string $memberName
    ): string
    {
        $propertyName = $this->symbolProvider->toPropertyName($memberName);
        if (!$this->isInheritedExceptionProperty($shapeId, $memberName)) {
            return $propertyName;
        }

        $candidate = self::INHERITED_EXCEPTION_PROPERTIES[$propertyName];
        $memberProperties = [];
        foreach ($this->model->getMembers($shapeId) as $name => $_) {
            $memberProperties[] = $this->symbolProvider->toPropertyName(
                $name
            );
        }

        while (in_array($candidate, $memberProperties, true)) {
            $candidate = '_' . $candidate;
        }

        return $candidate;
    }

    private function exceptionMessageParameter(string $shapeId): ?string
    {
        foreach ($this->model->getMembers($shapeId) as $memberName => $_) {
            $propertyName = $this->symbolProvider->toPropertyName(
                $memberName
            );
            if (\lcfirst($memberName) === 'message') {
                return $propertyName;
            }
        }

        return null;
    }

    private function toErrorFaultCase(?string $fault): string
    {
        return match ($fault) {
            'client' => 'Client',
            'server' => 'Server',
            default => 'Other'
        };
    }

    /**
     * Build PHPDoc `@param` lines for the constructor. Every constructor
     * slot is nullable so `null` can represent omission; effective-default
     * properties are materialized as non-null values in the body.
     *
     * @param array<string, array<string, mixed>> $members
     * @return string[]
     */
    private function buildParamDocs(
        string $shapeId,
        array $members
    ): array
    {
        $docs = [];

        foreach ($members as $memberName => $memberDef) {
            $phpType = $this->symbolProvider->toMemberType(
                $shapeId,
                $memberName
            );
            $propertyName = $this->symbolProvider->toPropertyName($memberName);
            if ($phpType->docType !== null
                && $phpType->docType !== $phpType->typeHint
            ) {
                $docType = $phpType->nullableDocType();
                $paramLine = "@param {$docType} \${$propertyName}";
                array_push($docs, ...$this->wrapParamLine($paramLine));
            }

            $memberTraits = $memberDef['traits'] ?? [];
            $memberDoc = $memberTraits['smithy.api#documentation'] ?? null;
            if ($memberDoc !== null) {
                $cleanDoc = $this->cleanDocumentation((string) $memberDoc);
                if ($phpType->docType === null
                    || $phpType->docType === $phpType->typeHint
                ) {
                    $docType = $phpType->nullable();
                    $paramLine = "@param {$docType} \${$propertyName} "
                        . $cleanDoc;
                    array_push($docs, ...$this->wrapParamLine($paramLine));
                }
            }
        }

        return $docs;
    }

    /**
     * Whether `SensitiveStringableTrait` should be emitted for the shape.
     */
    private function shouldEmitSensitiveStringable(string $shapeId): bool
    {
        return $this->sensitiveShapeDetector->containsSensitiveData($shapeId);
    }

}
