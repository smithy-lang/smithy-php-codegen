<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

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

        // Every structure implements `SerializableStructInterface`, which
        // covers the schema, the encode contract (`serialize`,
        // `serializeMembers`, `getMemberValue`), and the decode contract
        // (`fromFields`). The struct interface extends
        // `SerializableShapeInterface` (the universal `serialize($s, $idx)`
        // marker), so generated code only declares the leaf.
        $writer->addImport('Smithy\\Core\\Serde\\SerializableStructInterface');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
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
        $withArraySerialization =
            $this->settings->shouldGenerateArraySerialization($category);

        // Determine if this is an error shape
        $isError = $category === ShapeCategory::Error;
        $errorFault = null;
        $retryable = null;
        if ($isError) {
            $errorTraitValue = $this->model->getTrait(
                $shapeId,
                'smithy.api#error'
            );
            $errorFault = is_string($errorTraitValue)
                ? $errorTraitValue
                : 'other';
            $writer->addImport('Smithy\\Core\\Exception\\ErrorFault');

            // A modeled `retryable` error overrides the fault-derived retry
            // default: it classifies as retryable, and as a throttle when the
            // trait carries `throttling: true`.
            if ($this->model->hasTrait($shapeId, 'smithy.api#retryable')) {
                $trait = $this->model->getTrait(
                    $shapeId,
                    'smithy.api#retryable'
                );
                $retryable = [
                    'throttling' => \is_array($trait)
                        && ($trait['throttling'] ?? false) === true
                ];
                $writer->addImport('Smithy\\Core\\Retry\\RetrySafety');
            }
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

        $readonlyArrayAccessTrait = $withArrayAccess
            ? $writer->addImportReference(
                'Smithy\\Core\\Shape\\ReadonlyArrayAccessTrait',
                'SmithyReadonlyArrayAccessTrait',
                [$className]
            )
            : null;
        $arraySerializableTrait = $withArraySerialization
            ? $writer->addImportReference(
                'Smithy\\Core\\Shape\\ArraySerializableTrait',
                'SmithyArraySerializableTrait',
                [$className]
            )
            : null;

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

        // FromMembersTrait provides the `fromMembers()` sink used
        // by the runtime deserializer. Every generated struct participates in
        // the codec deserialization path, so the trait applies unconditionally.
        $writer->addImport('Smithy\\Core\\Shape\\FromMembersTrait');



        // Write doc comment from shape documentation trait
        $this->writeClassDoc($writer, $shapeId, $withArrayAccess);

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
        if ($readonlyArrayAccessTrait !== null) {
            $writer->writeLine("use {$readonlyArrayAccessTrait};");
        }
        if ($arraySerializableTrait !== null) {
            $writer->writeLine("use {$arraySerializableTrait};");
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
            $isError,
            $errorFault,
            $retryable
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
        $this->writeSerializeMethod($writer);
        $writer->writeLine();
        $this->writeSerializeMembersMethod($writer, $shapeId);
        $writer->writeLine();
        $this->writeGetMemberValueMethod($writer, $shapeId);
        $writer->writeLine();
        if ($members !== []) {
            $this->writeWithMemberValueMethod($writer, $shapeId);
            $writer->writeLine();
        }

        // `fromMembers()` (the deserializer's positional sink) is provided
        // by `FromMembersTrait`, added to the `use` list above. No per-shape
        // codegen for it.
        // Close the class
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function writeClassDoc(
        PhpCodeWriter $writer,
        string $shapeId,
        bool $withArrayAccess = false
    ): void
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

        // Offsets are wire member names; values are member values.
        if ($withArrayAccess) {
            if ($docLines !== []) {
                $docLines[] = '';
            }
            $docLines[] = '@implements \ArrayAccess<string, mixed>';
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
     *
     * @return array<string, array<string, mixed>>
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


    /**
     * @param array{throttling: bool}|null $retryable
     */
    private function writeConstructor(
        PhpCodeWriter $writer,
        string $shapeId,
        bool $isError = false,
        ?string $errorFault = null,
        ?array $retryable = null
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
                $this->writeRetryableOverride($writer, $retryable);
                $writer->closeBlock();
            } else {
                $writer->writeLine('public function __construct() {}');
            }
            return;
        }

        $allMembers = $this->getMembersIndexed($shapeId);

        // Scalar enum/intEnum and effective-default members are declared
        // separately and assigned in the constructor body.
        $explicitlyDeclared = [];
        foreach ($allMembers as $memberName => $memberDef) {
            if ($this->isScalarEnumMember($shapeId, $memberName)
                || $this->model->isMemberDefaulted($shapeId, $memberName)
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
                if ($this->isScalarEnumMember($shapeId, $memberName)) {
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
                $this->writeRetryableOverride($writer, $retryable);
            }
            foreach ($explicitlyDeclared as $memberName => $memberDef) {
                if ($this->isScalarEnumMember($shapeId, $memberName)) {
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

        $this->writeMemberAnnotations(
            $writer,
            $shapeId,
            $memberName,
            $phpType->docType !== null
                && $phpType->docType !== $phpType->typeHint
                ? $phpType->docType
                : null
        );
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

        // The parameter type matches PaginatedRequestInterface; the member
        // itself holds only its modeled token type.
        $writer->writeDocBlock([
            'Create a copy with a pagination token.',
            '',
            '@param string|array<array-key, mixed>|null $token Token of type '
                . "`{$phpType->nullableDocType()}`."
        ]);
        $writer->writeLine(
            'public function withPaginationToken('
                . 'string|array|null $token): self'
        );
        $writer->openBlock('{');
        $this->writeWithPaginationMemberBody($writer, $memberName, '$token');
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
        $this->writeWithPaginationMemberBody(
            $writer,
            $memberName,
            '$pageSize'
        );
        $writer->closeBlock();
    }

    /**
     * Emit a pagination copy method body that delegates to
     * `withMemberValue()`. Calling `schema()` first fills the member schema
     * statics.
     */
    private function writeWithPaginationMemberBody(
        PhpCodeWriter $writer,
        string $memberName,
        string $value
    ): void
    {
        $constName = $this->toSchemaConstName($memberName);
        $writer->writeLine('self::schema();');
        $writer->writeLine();
        $writer->writeLine(
            "return \$this->withMemberValue(self::\${$constName}, {$value});"
        );
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
            // A null input is stored as null, so the property is always
            // initialized.
            $writer->writeLine(
                "\$this->{$propertyName} = \${$parameterName} === null"
                . " || \${$parameterName} instanceof {$enumClass}"
                . " ? \${$parameterName}"
                . " : {$enumClass}::fromValue(\${$parameterName});"
            );
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
        string $memberName,
        ?string $varType = null
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
        if ($varType !== null) {
            $writer->writeDocBlock([...$annotations, "@var {$varType}"]);
        } elseif ($annotations !== []) {
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
        $isInheritedExceptionProperty =
            $this->isInheritedExceptionProperty($shapeId, $memberName);
        $isDefaulted = $mode === 'defaulted';

        // Annotations only attach to promoted-property declarations. Scalar
        // enum, effective-default, and inherited exception members declare
        // the property separately above the constructor.
        if (!$isEnumMember
            && !$isDefaulted
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
        $promotion = $isInheritedExceptionProperty
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

        // The type and `$name` must share the first line, or the tag does
        // not parse. Break the first line no earlier than the end of `$name`.
        $nameStart = strpos($line, ' $');
        $nameEnd = $nameStart === false
            ? false
            : strpos($line, ' ', $nameStart + 2);
        if ($nameStart !== false && $nameEnd === false) {
            return [$line];
        }

        $lines = [];
        $remaining = $line;
        $isFirst = true;
        if ($nameEnd !== false && $nameEnd > $maxContentWidth) {
            $lines[] = substr($line, 0, $nameEnd);
            $remaining = ltrim(substr($line, $nameEnd));
            $isFirst = false;
        }

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
    ): string
    {
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
     * Emit serializeMembers() using Schemas::$ClassName_MemberName references.
     * Ensures schema is initialized via Schemas init method, then references
     * member schemas directly from the centralized Schemas class.
     *
     * @param array<string, array<string, mixed>> $members
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
            $value = $this->memberValueExpression($shapeId, $memberName);
            $writer->writeLine("{$index} => {$value},");
            $index++;
        }

        $writer->writeLine('default => null,');
        $writer->popIndent();
        $writer->writeLine('};');
        $writer->closeBlock();
    }

    /**
     * Emit a `withMemberValue()` that copies members positionally.
     *
     * This skips the generic trait's schema walk, per-member match and sort.
     *
     * @return void
     */
    private function writeWithMemberValueMethod(
        PhpCodeWriter $writer,
        string $shapeId
    ): void
    {
        $values = [];
        foreach ($this->getMembersIndexed($shapeId) as $memberName => $_) {
            $values[] = $this->memberValueExpression($shapeId, $memberName);
        }

        $writer->writeDocBlock(['Return a copy with one member replaced.']);
        $writer->writeLine(
            'public function withMemberValue(Schema $member, mixed $value): '
                . 'static'
        );
        $writer->openBlock('{');
        $writer->writeLine('$members = [');
        $writer->pushIndent();
        $last = \count($values) - 1;
        foreach ($values as $i => $value) {
            $writer->writeLine($value . ($i === $last ? '' : ','));
        }
        $writer->popIndent();
        $writer->writeLine('];');
        $writer->writeLine('$members[$member->memberIndex] = $value;');
        $writer->writeLine();
        $writer->writeLine('return new self(...$members);');
        $writer->closeBlock();
    }

    /**
     * Build the expression that reads a member's value from `$this`.
     */
    private function memberValueExpression(
        string $shapeId,
        string $memberName
    ): string
    {
        $propertyName = $this->toMemberStoragePropertyName(
            $shapeId,
            $memberName
        );

        return "\$this->{$propertyName}";
    }



    /**
     * Emit a single member's serialization call using self::$S_MEMBER_NAME.
     * References the local private static member schema property.
     *
     * @param array<string, mixed> $memberDef
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

        // Defaulted members are never null, so they need no guard.
        if ($this->model->isMemberDefaulted($shapeId, $memberName)) {
            $writer->writeLine($writeCall);

            return;
        }

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
     * Emit retry-classification overrides for a modeled `retryable` error.
     *
     * The generated exception extends a base implementing
     * {@see \Smithy\Core\Retry\RetryInfoInterface}; setting the inherited
     * override fields makes the error classify as retryable (and as a
     * throttle when the trait carries `throttling: true`), regardless of the
     * fault-derived default.
     *
     * @param array{throttling: bool}|null $retryable
     */
    private function writeRetryableOverride(
        PhpCodeWriter $writer,
        ?array $retryable
    ): void
    {
        if ($retryable === null) {
            return;
        }

        $writer->writeLine(
            '$this->retrySafetyOverride = RetrySafety::Yes;'
        );
        $writer->writeLine(
            '$this->throttleOverride = '
            . ($retryable['throttling'] ? 'true' : 'false')
            . ';'
        );
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
