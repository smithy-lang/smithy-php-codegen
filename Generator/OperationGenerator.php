<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\PriorCodegenEvaluator;
use Smithy\Codegen\Writer\PhpCodeWriter;
use Smithy\Core\Model\EffectiveAuthSchemes;

/**
 * Generates PHP operation metadata classes from Smithy operation shapes.
 *
 * Generated operations expose precomputed Smithy metadata through
 * {@see \Smithy\Core\Shape\OperationInterface}.
 *
 * The operation schema carries HTTP trait data and codegen-resolved path
 * member indexes. Protocol runtimes use that schema data to build binding
 * extensions without re-walking the input structure.
 */
final class OperationGenerator
{
    private const DIRECT_OPERATION_TRAITS_BY_ID = [
        'smithy.rules#operationContextParams' => true,
        'smithy.rules#staticContextParams' => true,
    ];

    /** @var array<string, string>|null */
    private ?array $serviceIdByOperation = null;

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings,
        private readonly ?PriorCodegenEvaluator $priorCodegen = null
    ) {}

    /**
     * Generate an operation metadata class.
     *
     * @param string $shapeId Fully-qualified operation shape ID
     * @return GeneratedFile
     */
    public function generate(string $shapeId): GeneratedFile
    {
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException("Shape not found: {$shapeId}");
        }

        if (($shape['type'] ?? '') !== 'operation') {
            throw new \InvalidArgumentException(
                "Shape {$shapeId} is not an operation"
            );
        }

        $operationName = SmithyModel::getShapeName($shapeId);
        $className = $operationName . 'Operation';

        $namespace = $this->settings->resolveOperationsNamespace();
        $outputDir = $this->settings->resolveOperationsOutputDir();

        $writer = new PhpCodeWriter($namespace);

        // Resolve input/output class names and add imports
        $inputTarget = $shape['input']['target'] ?? 'smithy.api#Unit';
        $outputTarget = $shape['output']['target'] ?? 'smithy.api#Unit';

        $inputClass = $this->symbolProvider->toShortClassName($inputTarget);
        $writer->addImport($this->symbolProvider->toClassName($inputTarget));

        $outputClass = $this->symbolProvider->toShortClassName($outputTarget);
        $writer->addImport($this->symbolProvider->toClassName($outputTarget));

        // Resolve service and operation error classes.
        $serviceId = $this->resolveServiceIdForOperation($shapeId);
        $service = $serviceId === null
            ? null
            : $this->model->getShape($serviceId);
        $errors = [
            ...($service['errors'] ?? []),
            ...($shape['errors'] ?? [])
        ];
        $errorMap = [];
        $errorClasses = [];
        foreach ($errors as $error) {
            $errorTarget = $error['target'] ?? null;
            if ($errorTarget !== null
                && !isset($errorClasses[$errorTarget])
            ) {
                $writer->addImport(
                    $this->symbolProvider->toClassName($errorTarget)
                );
                $shortName = $this->symbolProvider->toShortClassName(
                    $errorTarget
                );
                $errorClasses[$errorTarget] = $shortName;
                $entry = [
                    'class' => $shortName,
                    'shapeId' => $errorTarget
                ];
                $errorMap[$errorTarget] = $entry;
                $errorMap[SmithyModel::getShapeName($errorTarget)] = $entry;

                foreach ($this->settings->errorDiscriminators[$errorTarget]
                    ?? []
                    as $discriminator
                ) {
                    $errorMap[$discriminator] = $entry;
                }
            }
        }

        // Get HTTP binding info
        $traits = $shape['traits'] ?? [];
        $httpTrait = $traits['smithy.api#http'] ?? null;
        $paginatedTrait =
            $this->model->getValidatedPaginationTrait($shapeId);
        $waitableTrait = null;
        if (isset($traits['smithy.waiters#waitable'])) {
            $this->model->getValidatedWaiters($shapeId);
            $waitableTrait = $traits['smithy.waiters#waitable'];
        }
        $authSchemes = $serviceId === null
            ? [EffectiveAuthSchemes::NO_AUTH]
            : $this->model->getEffectiveAuthSchemeIds(
                $serviceId,
                $shapeId,
                $this->settings->authSchemeIds
            );

        // Imports - schema + interface(s) + provider when needed
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Serde\\SerializableStructInterface');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        $operationInterface = $writer->addImportReference(
            'Smithy\\Core\\Shape\\OperationInterface',
            'SmithyOperation',
            [$className]
        );
        $serviceExceptionClass = $writer->addImportReference(
            $serviceId === null
                && $this->settings->serviceExceptionClass === null
                ? 'Smithy\\Core\\Exception\\ApiException'
                : $this->settings->resolveServiceExceptionClass(),
            'ServiceException',
            [$className]
        );
        // HTTP binding data is emitted as schema traits for the protocol
        // layer to read through the operation schema.

        // Resolve member-scan results at codegen time. Each is either:
        //   - null (no qualifying member)
        //   - ['inputClass' => ..., 'index' => N] for an input-side member
        //   - ['outputClass' => ..., 'index' => N] for an output-side member
        //
        // `@idempotencyToken` is no longer emitted as a per-operation method.
        // The runtime reads it off the precomputed
        // {@see HttpStructBindingExtension::$idempotencyTokenMemberIndex},
        // so the autofill path doesn't traverse the operation object.
        $inputStreamMember = $this->findMemberWithTargetTrait(
            $inputTarget,
            'smithy.api#streaming'
        );
        $outputStreamMember = $this->findMemberWithTargetTrait(
            $outputTarget,
            'smithy.api#streaming'
        );

        // Documentation
        $documentation = $traits['smithy.api#documentation'] ?? null;
        if ($documentation !== null) {
            $cleanDoc = $this->cleanDocumentation((string) $documentation);
            $writer->writeDocBlock([$cleanDoc]);
        }

        // Every generated operation provides its service-specific fallback.
        $writer->writeClassDeclaration(
            "final class {$className}",
            [$operationInterface]
        );

        $writer->openBlock('{');

        $writer->writeLine("public const NAME = '{$operationName}';");
        $writer->writeLine("public const ID = '{$shapeId}';");
        $writer->writeLine(
            $serviceId === null
                ? 'public const SERVICE_ID = null;'
                : "public const SERVICE_ID = '{$serviceId}';"
        );
        $writer->writeLine("public const INPUT_CLASS = {$inputClass}::class;");
        $writer->writeLine(
            "public const OUTPUT_CLASS = {$outputClass}::class;"
        );

        $writer->writeLine('public const AUTH_SCHEMES = [');
        $writer->pushIndent();
        foreach ($authSchemes as $schemeId) {
            $escaped = \addcslashes($schemeId, "\\'");
            $writer->writeLine("'{$escaped}',");
        }
        $writer->popIndent();
        $writer->writeLine('];');

        if ($errorMap === []) {
            $writer->writeLine('public const ERRORS = [];');
        } else {
            $writer->writeLine('public const ERRORS = [');
            $writer->pushIndent();
            foreach ($errorMap as $discriminator => $errorInfo) {
                $escaped = \addcslashes($discriminator, "\\'");
                $errorShapeId = \addcslashes(
                    $errorInfo['shapeId'],
                    "\\'"
                );
                $writer->writeLine(
                    "'{$escaped}' => '{$errorShapeId}',"
                );
            }
            $writer->popIndent();
            $writer->writeLine('];');
        }

        $extraConstants = $this->settings->operationConstants[$shapeId] ?? [];

        $requestTarget = isset($extraConstants['TARGET'])
            ? (string) $extraConstants['TARGET']
            : null;

        foreach ($extraConstants as $constName => $constValue) {
            $escaped = \addcslashes((string) $constValue, "\\'");
            $writer->writeLine("public const {$constName} = '{$escaped}';");
        }
        $writer->writeLine();

        $writer->writeLine('private static ?self $INSTANCE = null;');
        $writer->writeLine('private static ?Schema $SCHEMA = null;');
        $writer->writeLine();

        $writer->writeLine('public static function instance(): self');
        $writer->openBlock('{');
        $writer->writeLine('return self::$INSTANCE ??= new self();');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('private function __construct() {}');
        $writer->writeLine();

        $writer->writeLine('public function getName(): string');
        $writer->openBlock('{');
        $writer->writeLine('return self::NAME;');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getId(): string');
        $writer->openBlock('{');
        $writer->writeLine('return self::ID;');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getServiceId(): ?string');
        $writer->openBlock('{');
        $writer->writeLine('return self::SERVICE_ID;');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine(
            'public function getServiceExceptionClass(): string'
        );
        $writer->openBlock('{');
        $writer->writeLine("return {$serviceExceptionClass}::class;");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getRuntimeIdentity(): string');
        $writer->openBlock('{');
        $writer->writeLine('return self::class;');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getEffectiveAuthSchemes(): array');
        $writer->openBlock('{');
        $writer->writeLine('return self::AUTH_SCHEMES;');
        $writer->closeBlock();
        $writer->writeLine();


        $this->writeOperationSchemaMethod(
            $writer,
            $shapeId,
            $httpTrait,
            $paginatedTrait,
            $waitableTrait,
            $inputTarget,
            $errorMap,
            isset($traits['smithy.api#httpChecksumRequired']),
            isset($traits['smithy.api#readonly']),
            isset($traits['smithy.api#idempotent']),
            $traits['smithy.api#requestCompression']['encodings'] ?? null,
            $this->settings->operationSchemaTraits[$shapeId] ?? [],
            $this->filterDirectTraitsById(
                $traits,
                self::DIRECT_OPERATION_TRAITS_BY_ID
            )
        );
        $writer->writeLine();

        $writer->writeLine('public function getSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::schema();');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public static function inputSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine("return {$inputClass}::schema();");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getInputSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::inputSchema();');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public static function outputSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine("return {$outputClass}::schema();");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getOutputSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::outputSchema();');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock([
            '@return list<Schema> Modeled error schemas.'
        ]);
        $writer->writeLine('public function getErrorSchemas(): array');
        $writer->openBlock('{');
        if ($errorClasses === []) {
            $writer->writeLine('return [];');
        } else {
            $writer->writeLine('return [');
            $writer->pushIndent();
            foreach ($errorClasses as $errorClass) {
                $writer->writeLine("{$errorClass}::schema(),");
            }
            $writer->popIndent();
            $writer->writeLine('];');
        }
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine(
            'public function createInput(array $members): '
                . 'SerializableStructInterface'
        );
        $writer->openBlock('{');
        $writer->writeLine("return {$inputClass}::fromMembers(\$members);");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine(
            'public function createOutput(array $members): '
                . 'SerializableStructInterface'
        );
        $writer->openBlock('{');
        $writer->writeLine("return {$outputClass}::fromMembers(\$members);");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine('public function getRequestTarget(): ?string');
        $writer->openBlock('{');
        $writer->writeLine(
            $requestTarget === null
                ? 'return null;'
                : 'return self::TARGET;'
        );
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeLine(
            'public function resolveErrorSchema(string $discriminator): ?Schema'
        );
        $writer->openBlock('{');
        if ($errorMap === []) {
            $writer->writeLine('return null;');
        } else {
            $writer->writeLine('return match ($discriminator) {');
            $writer->pushIndent();
            foreach ($errorMap as $discriminator => $errorInfo) {
                $escaped = \addcslashes($discriminator, "\\'");
                $writer->writeLine(
                    "'{$escaped}' => {$errorInfo['class']}::schema(),"
                );
            }
            $writer->writeLine('default => null');
            $writer->popIndent();
            $writer->writeLine('};');
        }
        $writer->closeBlock();
        $writer->writeLine();

        $this->writeMemberScanMethod(
            $writer,
            'getInputStreamMember',
            $inputStreamMember,
            $inputClass,
            null
        );
        $writer->writeLine();

        $this->writeMemberScanMethod(
            $writer,
            'getOutputStreamMember',
            $outputStreamMember,
            null,
            $outputClass
        );

        // The HTTP binding extension lives on the operation schema.

        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    /**
     * Find the first member of `$structureId` whose target shape carries
     * `$traitId`. Returns ['index' => N, 'name' => string] or null.
     *
     * Used for `@streaming`, which sits on the targeted blob/union shape
     * rather than on the structure member itself.
     *
     * @return array{index: int, name: string}|null
     */
    private function findMemberWithTargetTrait(
        string $structureId,
        string $traitId
    ): ?array
    {
        $idx = 0;
        $members = $this->getMembersIndexed($structureId);
        foreach ($members as $name => $def) {
            $target = $def['target'] ?? null;
            if ($target !== null && $this->model->hasTrait($target, $traitId)) {
                return ['index' => $idx, 'name' => $name];
            }
            $idx++;
        }
        return null;
    }

    /**
     * Emit one of the `?Schema` member-scan accessors with a body that
     * either returns null or fetches a member by its codegen-resolved
     * positional index from the structure schema's members.
     *
     * @param array{index:int,name:string}|null $memberInfo
     * @param string|null $inputShortClass  Input shape short class, if the lookup is input-side.
     * @param string|null $outputShortClass Output shape short class, if the lookup is output-side.
     */
    private function writeMemberScanMethod(
        PhpCodeWriter $writer,
        string $methodName,
        ?array $memberInfo,
        ?string $inputShortClass,
        ?string $outputShortClass
    ): void
    {
        $writer->writeLine("public function {$methodName}(): ?Schema");
        $writer->openBlock('{');
        if ($memberInfo === null) {
            $writer->writeLine('return null;');
        } else {
            $sourceShortClass = $inputShortClass ?? $outputShortClass;
            // Should be impossible to hit null here - codegen only produces a
            // memberInfo when the relevant input/output target exists.
            $writer->writeLine(
                "return {$sourceShortClass}::schema()"
                . "->getMembersByIndex()[{$memberInfo['index']}];"
            );
        }
        $writer->closeBlock();
    }

    /**
     * Emit `public static function schema(): Schema` with all operation-level
     * traits baked in.
     *
     * The `@http` trait carries the smithy-spec fields (`method`, `uri`, `code`)
     * plus codegen-precomputed `pathSegments` and `pathQueryString`. The runtime
     * `HttpStructBindingExtensionProvider` reads these directly when building
     * the `HttpOperationBindingExtension` instead of re-walking the input
     * schema's members.
     *
     * @param array<string, mixed>|null $httpTrait
     * @param array<string, string>|null $paginatedTrait
     * @param mixed $waitableTrait
     * @param string $inputTarget Input shape ID for resolving members.
     * @param array<string, array{class: string, shapeId: string}> $errorMap
     *        Error discriminator metadata.
     * @param array<int|string, mixed> $configuredTraits
     * @param array<string, mixed> $directTraitsById
     */
    private function writeOperationSchemaMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        ?array $httpTrait,
        ?array $paginatedTrait,
        mixed $waitableTrait,
        string $inputTarget,
        array $errorMap,
        bool $httpChecksumRequired,
        bool $readOnly,
        bool $idempotent,
        ?array $requestCompressionEncodings,
        array $configuredTraits,
        array $directTraitsById
    ): void
    {
        $writer->writeDocBlock(['Get the shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::$SCHEMA ??= new Schema(');
        $writer->pushIndent();
        $writer->writeLine("id: '{$shapeId}',");
        $writer->writeLine('type: ShapeType::Operation,');

        $traitParts = [];
        if ($httpTrait !== null) {
            $traitParts[] = $this->buildHttpTraitArrayLiteral(
                $httpTrait,
                $inputTarget
            );
        }
        if ($paginatedTrait !== null) {
            $traitParts[] =
                $this->buildPaginatedTraitArrayLiteral($paginatedTrait);
        }
        if ($waitableTrait !== null) {
            $traitParts[] =
                $this->buildWaitableTraitArrayLiteral($waitableTrait);
        }
        if ($errorMap !== []) {
            $traitParts[] = 'Schema::T_ERRORS => self::ERRORS,';
        }
        if ($httpChecksumRequired) {
            $traitParts[] = 'Schema::T_HTTP_CHECKSUM_REQUIRED => true,';
        }
        if ($readOnly) {
            $traitParts[] = 'Schema::T_READ_ONLY => true,';
        }
        if ($idempotent) {
            $traitParts[] = 'Schema::T_IDEMPOTENT => true,';
        }
        if ($requestCompressionEncodings !== null
            && $requestCompressionEncodings !== []
        ) {
            $encodings = [];
            foreach ($requestCompressionEncodings as $encoding) {
                $encodings[] = $this->exportLiteral((string) $encoding);
            }
            $traitParts[] = 'Schema::T_REQUEST_COMPRESSION => ['
                . \implode(', ', $encodings)
                . '],';
        }
        $configuredTraitsById = [];
        foreach ($configuredTraits as $index => $value) {
            if (\is_string($index)) {
                $configuredTraitsById[$index] = $value;
                $index = $this->settings->schemaTraitIndices[$index];
            }
            $traitParts[] = $index
                . ' => '
                . $this->exportLiteral($value)
                . ',';
        }

        if (!empty($traitParts)) {
            $writer->writeLine('traits: [');
            $writer->pushIndent();
            foreach ($traitParts as $entry) {
                // Multi-line trait values (the @http array) are rendered with
                // their own opening and closing braces. Do not flatten
                // their commas here.
                foreach (\explode("\n", $entry) as $line) {
                    $writer->writeLine($line);
                }
            }
            $writer->popIndent();
            $writer->writeLine('],');
        }
        if ($configuredTraitsById !== []) {
            $writer->writeLine('traitsById: [');
            $writer->pushIndent();
            foreach ($configuredTraitsById as $traitId => $value) {
                $writer->writeLine(
                    $this->exportLiteral($traitId)
                        . ' => '
                        . $this->exportLiteral($value)
                        . ','
                );
            }
            $writer->popIndent();
            $writer->writeLine('],');
        }
        if ($directTraitsById !== []) {
            $writer->writeLine('directTraitsById: [');
            $writer->pushIndent();
            foreach ($directTraitsById as $traitId => $value) {
                $writer->writeLine(
                    $this->exportLiteral($traitId)
                        . ' => '
                        . $this->exportLiteral($value)
                        . ','
                );
            }
            $writer->popIndent();
            $writer->writeLine('],');
        }

        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Render the effective `@paginated` trait as a PHP array literal.
     *
     * @param array<string, string> $paginatedTrait
     */
    private function buildPaginatedTraitArrayLiteral(
        array $paginatedTrait
    ): string
    {
        $lines = ['Schema::T_PAGINATED => ['];
        $fields = ['inputToken', 'outputToken', 'items', 'pageSize'];
        foreach ($fields as $field) {
            if (!isset($paginatedTrait[$field])) {
                continue;
            }

            $value = \addcslashes($paginatedTrait[$field], "\\'");
            $lines[] = "    '{$field}' => '{$value}',";
        }
        $lines[] = '],';

        return \implode("\n", $lines);
    }

    /**
     * Render the `@waitable` trait as a readable nested PHP array.
     *
     * @param array<string, mixed> $waitableTrait
     */
    private function buildWaitableTraitArrayLiteral(
        array $waitableTrait
    ): string
    {
        $lines = $this->arrayLiteralLines($waitableTrait);
        $lines[0] = 'Schema::T_WAITABLE => ' . \ltrim($lines[0]);
        $last = \array_key_last($lines);
        $lines[$last] .= ',';

        return \implode("\n", $lines);
    }

    /**
     * Render a nested PHP array with deterministic indentation.
     *
     * @param array<mixed> $value
     *
     * @return list<string>
     */
    private function arrayLiteralLines(
        array $value,
        int $depth = 0
    ): array
    {
        $indent = \str_repeat('    ', $depth);
        if ($value === []) {
            return [$indent . '[]'];
        }

        $lines = [$indent . '['];
        $entryIndent = \str_repeat('    ', $depth + 1);
        $isList = \array_is_list($value);
        foreach ($value as $key => $member) {
            $prefix = $isList
                ? ''
                : $this->exportLiteral($key) . ' => ';
            if (!\is_array($member)) {
                $lines[] = $entryIndent
                    . $prefix
                    . $this->exportLiteral($member)
                    . ',';
                continue;
            }

            $nested = $this->arrayLiteralLines($member, $depth + 1);
            $lines[] = $entryIndent
                . $prefix
                . \ltrim(\array_shift($nested));
            if ($nested === []) {
                $lines[\array_key_last($lines)] .= ',';
                continue;
            }
            $nestedLast = \array_key_last($nested);
            $nested[$nestedLast] .= ',';
            \array_push($lines, ...$nested);
        }
        $lines[] = $indent . ']';

        return $lines;
    }

    /**
     * Render the `@http` trait as a PHP array literal embedded in `traits: [...]`.
     *
     * Output:
     * ```
     * Schema::T_HTTP => [
     *     'method' => 'PUT',
     *     'uri' => '/{Collection}/{Item+}',
     *     'code' => 200,
     *     'pathSegments' => [['/', null, false], [null, 0, false], ...],
     *     'pathQueryString' => 'x-id=PutObject',
     * ],
     * ```
     *
     * @param array<string,mixed> $httpTrait
     * @param string $inputTarget Input shape ID used to resolve label member
     *                           indexes.
     */
    private function buildHttpTraitArrayLiteral(array $httpTrait, string $inputTarget): string
    {
        $method = $httpTrait['method'] ?? null;
        $uri = $httpTrait['uri'] ?? null;
        $code = $httpTrait['code'] ?? null;

        $pathOnly = $uri;
        $queryLiteral = null;
        if ($uri !== null && \str_contains($uri, '?')) {
            [$pathOnly, $queryLiteral] = \explode('?', $uri, 2);
        }

        $lines = ['Schema::T_HTTP => ['];
        if ($method !== null) {
            $lines[] = "    'method' => "
                . $this->exportLiteral($method)
                . ',';
        }
        if ($uri !== null) {
            $lines[] = "    'uri' => "
                . $this->exportLiteral($uri)
                . ',';
        }
        if ($code !== null) {
            $lines[] = "    'code' => {$code},";
        }

        // pathSegments - only when the path has `{label}` placeholders.
        if ($pathOnly !== null && \str_contains($pathOnly, '{')) {
            $memberIndexMap = [];
            $idx = 0;
            $inputMembers = $this->getMembersIndexed($inputTarget);
            foreach ($inputMembers as $memberName => $_def) {
                $memberIndexMap[$memberName] = $idx++;
            }
            $segments = $this->parsePathSegments($pathOnly);
            $segmentEntries = [];
            foreach ($segments as [$literal, $labelName, $greedy]) {
                $litStr = $literal !== null
                    ? $this->exportLiteral($literal)
                    : 'null';
                $idxStr = 'null';
                if ($labelName !== null) {
                    if (!isset($memberIndexMap[$labelName])) {
                        throw new \InvalidArgumentException(
                            "Path label {{$labelName}} has no matching member "
                                . "in {$inputTarget}"
                        );
                    }
                    $idxStr = (string) $memberIndexMap[$labelName];
                }
                $greedyStr = $greedy ? 'true' : 'false';
                $segmentEntries[] = "[{$litStr}, {$idxStr}, {$greedyStr}]";
            }
            $lines[] = "    'pathSegments' => ["
                . \implode(', ', $segmentEntries)
                . '],';
        }

        // pathQueryString - only when the URI has a static query literal.
        // Names and values are rawurlencoded once at codegen time.
        if ($queryLiteral !== null) {
            $fragments = [];
            foreach (\explode('&', $queryLiteral) as $pair) {
                $parts = \explode('=', $pair, 2);
                $fragment = \rawurlencode($parts[0]);
                if (isset($parts[1])) {
                    $fragment .= '=' . \rawurlencode($parts[1]);
                }
                $fragments[] = $fragment;
            }
            $queryString = \str_replace(
                "'",
                "\\'",
                \implode('&', $fragments)
            );
            $lines[] = "    'pathQueryString' => '{$queryString}',";
        }

        $lines[] = '],';
        return \implode("\n", $lines);
    }

    /**
     * Get structure members in generated schema-index order.
     */
    private function getMembersIndexed(string $shapeId): array
    {
        return $this->model->getMembersOrdered(
            $shapeId,
            $this->priorCodegen?->memberOrderFor($shapeId)
        );
    }

    /**
     * Resolve the service whose closure contains an operation.
     */
    private function resolveServiceIdForOperation(
        string $operationId
    ): ?string
    {
        if ($this->serviceIdByOperation === null) {
            $this->serviceIdByOperation = [];
            foreach (\array_keys($this->model->getServiceShapes())
                as $serviceId
            ) {
                foreach ($this->model->getServiceOperationIds($serviceId)
                    as $serviceOperationId
                ) {
                    $this->serviceIdByOperation[$serviceOperationId]
                        ??= $serviceId;
                }
            }
        }

        return $this->serviceIdByOperation[$operationId] ?? null;
    }

    private function exportLiteral(mixed $value): string
    {
        return PhpCodeWriter::exportLiteral($value);
    }

    /**
     * @param array<string, mixed> $traits
     * @param array<string, true> $preservedTraits
     *
     * @return array<string, mixed>
     */
    private function filterDirectTraitsById(
        array $traits,
        array $preservedTraits
    ): array
    {
        $directTraitsById = [];
        foreach ($preservedTraits as $traitId => $_) {
            if (\array_key_exists($traitId, $traits)) {
                $directTraitsById[$traitId] = $traits[$traitId];
            }
        }

        return $directTraitsById;
    }

    private function cleanDocumentation(string $text): string
    {
        $text = \strip_tags($text);
        $text = \preg_replace('/\s+/', ' ', $text) ?? '';

        return \trim($text);
    }

    /**
     * Parse a URI path template into precomputed segments.
     *
     * Given "/{Collection}/{Item+}", produces:
     *   [['/', null, false], [null, 'Collection', false], ['/', null, false], [null, 'Item', true]]
     *
     * @return list<array{?string, ?string, bool}>
     */
    private function parsePathSegments(string $path): array
    {
        $segments = [];
        $offset = 0;
        $len = strlen($path);

        while ($offset < $len) {
            $bracePos = strpos($path, '{', $offset);
            if ($bracePos === false) {
                $segments[] = [substr($path, $offset), null, false];
                break;
            }

            if ($bracePos > $offset) {
                $segments[] = [
                    substr($path, $offset, $bracePos - $offset),
                    null,
                    false
                ];
            }

            $closePos = strpos($path, '}', $bracePos);
            if ($closePos === false) {
                $segments[] = [substr($path, $bracePos), null, false];
                break;
            }

            $label = substr($path, $bracePos + 1, $closePos - $bracePos - 1);
            $greedy = str_ends_with($label, '+');
            if ($greedy) {
                $label = substr($label, 0, -1);
            }

            $segments[] = [null, $label, $greedy];
            $offset = $closePos + 1;
        }

        return $segments;
    }

}
