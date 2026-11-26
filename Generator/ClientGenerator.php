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
use Smithy\Codegen\ServiceProjection;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generate a typed service client.
 */
final class ClientGenerator
{
    /** Shared paginator returned by generated paginated methods. */
    private const PAGINATOR = 'Smithy\\Client\\Pagination\\Paginator';

    /** Strongly-typed collection of client plugins. */
    private const PLUGIN_COLLECTION =
        'Smithy\\Client\\Plugin\\PluginCollection';

    /** Per-request overrides accepted by generated operation methods. */
    private const REQUEST_OVERRIDE_CONFIG =
        'Smithy\\Client\\RequestOverrideConfig';

    /** Runtime types used by generated waiter methods. */
    private const WAITER_CONFIG = 'Smithy\\Client\\Waiter\\WaiterConfig';
    private const WAITER_FAILURE =
        'Smithy\\Client\\Waiter\\WaiterFailureException';
    private const WAITER_RESULT = 'Smithy\\Client\\Waiter\\WaiterResult';

    /**
     * Waiter-name suffix to appended resource noun and reported state.
     *
     * Longer compound suffixes must precede their simple forms.
     */
    private const WAITER_STATE_DESCRIPTIONS = [
        'RegistrationComplete' => ['registration', 'complete'],
        'CreateComplete' => ['creation', 'complete'],
        'DeleteComplete' => ['deletion', 'complete'],
        'ExecuteComplete' => ['execution', 'complete'],
        'ImportComplete' => ['import', 'complete'],
        'RollbackComplete' => ['rollback', 'complete'],
        'UpdateComplete' => ['update', 'complete'],
        'ReadyForDeployment' => [null, 'ready for deployment'],
        'SchedulingBlocked' => [null, 'blocked from scheduling'],
        'NotExists' => [null, 'no longer existing'],
        'Deregistered' => [null, 'deregistered'],
        'Disassociated' => [null, 'disassociated'],
        'Completed' => [null, 'completed'],
        'Successful' => [null, 'successful'],
        'Associated' => [null, 'associated'],
        'Registered' => [null, 'registered'],
        'Terminated' => [null, 'terminated'],
        'Validated' => [null, 'validated'],
        'Available' => [null, 'available'],
        'Cancelled' => [null, 'cancelled'],
        'Accepted' => [null, 'accepted'],
        'Changed' => [null, 'changed'],
        'Created' => [null, 'created'],
        'Deployed' => [null, 'deployed'],
        'Deleted' => [null, 'deleted'],
        'Disabled' => [null, 'disabled'],
        'Enabled' => [null, 'enabled'],
        'Imported' => [null, 'imported'],
        'Inactive' => [null, 'inactive'],
        'InService' => [null, 'in service'],
        'InUse' => [null, 'in use'],
        'Executed' => [null, 'executed'],
        'Finished' => [null, 'finished'],
        'Offline' => [null, 'offline'],
        'Online' => [null, 'online'],
        'Operational' => [null, 'operational'],
        'Passed' => [null, 'passed'],
        'Restored' => [null, 'restored'],
        'Running' => [null, 'running'],
        'Scheduling' => [null, 'scheduling'],
        'Started' => [null, 'started'],
        'Stopped' => [null, 'stopped'],
        'Succeeded' => [null, 'successful'],
        'Updated' => [null, 'updated'],
        'Complete' => [null, 'complete'],
        'Exists' => [null, 'existing'],
        'Active' => [null, 'active'],
        'Failed' => [null, 'failed'],
        'Issued' => [null, 'issued'],
        'Ready' => [null, 'ready'],
        'Stable' => [null, 'stable'],
        'Standby' => [null, 'in standby'],
        'Success' => [null, 'successful'],
        'Ok' => [null, 'OK']
    ];

    /**
     * Maximum column budget for a single-line method signature. The
     * generated method sits four spaces in from the file's left margin,
     * so a signature of `public function fooBar(FooBarRequest $input):
     * FooBarResponse` overflows when input + output class names push the
     * emitted line past 120 columns. Signatures that overflow render in
     * the multi-line form. short signatures stay on one line.
     */
    private const MAX_METHOD_SIGNATURE_WIDTH = 120;

    /**
     * Method body indent - four spaces, matching the class-body indent
     * level. Used for measurement so the wrapping decision counts the
     * leading whitespace that PhpCodeWriter will emit.
     */
    private const METHOD_INDENT = '    ';

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate the client class file for the given service.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID.
     */
    public function generate(
        string $serviceShapeId,
        ServiceProjection $projection
    ): GeneratedFile
    {
        $operations = $this->operationsForService(
            $serviceShapeId,
            $projection
        );

        $baseClass = $this->settings->clientBaseClass;
        $baseClassShort = $this->shortName($baseClass);

        $shortName = $this->clientShortName($serviceShapeId);
        $className = $shortName . 'Client';
        $interfaceName = $className . 'Interface';
        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        $writer = new PhpCodeWriter($namespace);
        $writer->addImport($baseClass);

        if ($operations !== []) {
            $writer->addImport(self::REQUEST_OVERRIDE_CONFIG);
        }

        // Import each operation's Operation / Request / Response classes
        // exactly once (multiple operations may share a common Input /
        // Output type in future models, so key by FQCN).
        foreach ($operations as $op) {
            $writer->addImport($op['operationFqcn']);
            $writer->addImport($op['inputFqcn']);
            $writer->addImport($op['outputFqcn']);
            if ($op['paginated']) {
                $writer->addImport(self::PAGINATOR);
            }
            if ($op['waiters'] !== []) {
                $writer->addImport(self::WAITER_CONFIG);
                $writer->addImport(self::WAITER_FAILURE);
                $writer->addImport(self::WAITER_RESULT);
            }
        }

        $documentation = $this->model->getTrait(
            $serviceShapeId,
            'smithy.api#documentation'
        );
        $docLines = [
            "Client for the {$shortName} service.",
            '',
            'Emitted by ClientGenerator. Every operation method is a thin',
            "typed wrapper around {$baseClassShort}::executeOperation().",
            'the pipeline, retry loop, endpoint resolver, signer, and',
            'transport all live on the base class.'
        ];
        if ($documentation !== null) {
            $docLines[] = '';
            $docLines[] = $this->cleanDocumentation((string) $documentation);
        }
        $writer->writeDocBlock($docLines);

        $writer->writeLine(
            "final class {$className} extends {$baseClassShort} "
                . "implements {$interfaceName}"
        );
        $writer->openBlock('{');

        $configClass = $writer->addImportReference(
            $this->settings->clientConfigClass,
            'ConfiguredClientConfig',
            [$className]
        );
        $pluginCollectionClass = $writer->addImportReference(
            self::PLUGIN_COLLECTION,
            'RuntimePluginCollection',
            [$className]
        );
        $serviceClass = $shortName . 'Service';
        $serviceReference = $writer->addImportReference(
            $namespace . '\\' . $serviceClass,
            $serviceClass . 'Metadata',
            [$className]
        );
        $writer->writeLine('public function __construct(');
        $writer->pushIndent();
        $writer->writeLine("{$configClass} \$config,");
        $writer->writeLine(
            "{$pluginCollectionClass} \$plugins = "
                . "new {$pluginCollectionClass}()"
        );
        $writer->popIndent();
        $writer->writeLine(')');
        $writer->openBlock('{');
        $writer->writeLine('parent::__construct(');
        $writer->pushIndent();
        $writer->writeLine(
            "\$config->withService({$serviceReference}::instance()),"
        );
        $writer->writeLine('$plugins');
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
        if ($operations !== []) {
            $writer->writeLine();
        }

        $first = true;
        foreach ($operations as $op) {
            if (!$first) {
                $writer->writeLine();
            }
            $first = false;
            $this->writeOperationMethod($writer, $op);
            if ($op['paginated']) {
                $writer->writeLine();
                $this->writePaginatedOperationMethod($writer, $op);
            }
            foreach ($op['waiters'] as $waiterName => $waiter) {
                $writer->writeLine();
                $this->writeWaiterMethod(
                    $writer,
                    $op,
                    $waiterName,
                    $waiter
                );
            }
        }

        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';
        return new GeneratedFile($filePath, $writer->toFileContent());
    }

    /**
     * Generate the public client interface file for the given service.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID.
     */
    public function generateInterface(
        string $serviceShapeId,
        ServiceProjection $projection
    ): GeneratedFile
    {
        $shortName = $this->clientShortName($serviceShapeId);
        $interfaceName = $shortName . 'ClientInterface';
        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        $operations = $this->operationsForService(
            $serviceShapeId,
            $projection
        );

        $writer = new PhpCodeWriter($namespace);
        if ($operations !== []) {
            $writer->addImport(self::REQUEST_OVERRIDE_CONFIG);
        }
        foreach ($operations as $op) {
            if ($op['paginated']) {
                $writer->addImport(self::PAGINATOR);
            }
            if ($op['waiters'] !== []) {
                $writer->addImport(self::WAITER_CONFIG);
                $writer->addImport(self::WAITER_FAILURE);
                $writer->addImport(self::WAITER_RESULT);
            }
        }
        foreach ($operations as &$op) {
            $op['inputShort'] = $writer->addImportReference(
                $op['inputFqcn'],
                $op['inputShort'] . 'Shape',
                [$interfaceName]
            );
            $op['outputShort'] = $writer->addImportReference(
                $op['outputFqcn'],
                $op['outputShort'] . 'Shape',
                [$interfaceName]
            );
        }
        unset($op);
        $baseInterface = $writer->addImportReference(
            $this->settings->clientBaseInterface,
            'ConfiguredClientInterface',
            [$interfaceName]
        );

        $writer->writeDocBlock([
            "Public contract for the {$shortName} service client."
        ]);
        $writer->writeLine(
            "interface {$interfaceName} extends {$baseInterface}"
        );
        $writer->openBlock('{');

        $first = true;
        foreach ($operations as $op) {
            if (!$first) {
                $writer->writeLine();
            }
            $first = false;
            $this->writeOperationDeclaration($writer, $op, ';');
            if ($op['paginated']) {
                $writer->writeLine();
                $this->writePaginatedOperationDeclaration(
                    $writer,
                    $op,
                    ';'
                );
            }
            foreach ($op['waiters'] as $waiterName => $waiter) {
                $writer->writeLine();
                $this->writeWaiterDeclaration(
                    $writer,
                    $op,
                    $waiterName,
                    $waiter,
                    ';'
                );
            }
        }

        $writer->closeBlock();

        $filePath = $outputDir
            . DIRECTORY_SEPARATOR
            . $interfaceName
            . '.php';
        return new GeneratedFile($filePath, $writer->toFileContent());
    }

    /**
     * Emit one `public function foo(...)` block into the writer.
     *
     * `$op` is one of the tuples returned by {@see collectOperations()}.
     * the caller is responsible for placement (imports, blank-line
     * separators between methods).
     *
     * @param array{
     *     methodName: string,
     *     operationShort: string,
     *     operationFqcn: string,
     *     inputShort: string,
     *     inputFqcn: string,
     *     outputShort: string,
     *     outputFqcn: string,
     *     inputRequired: bool,
     *     paginated: bool,
     *     waiters: array<string, array<string, mixed>>,
     *     documentation: ?string,
     * } $op
     *
     * @return void
     */
    private function writeOperationMethod(PhpCodeWriter $writer, array $op): void
    {
        $this->writeOperationDeclaration($writer, $op);
        $writer->openBlock('{');
        $writer->writeLine("/** @var {$op['outputShort']} */");
        $writer->writeLine('return $this->executeOperation(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $inputArgument = $op['inputRequired']
            ? '$input,'
            : "\$input ?? new {$op['inputShort']}(),";
        $writer->writeLine(
            $inputArgument
        );
        $writer->writeLine('$overrides,');
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Emit an operation declaration with an optional line suffix.
     *
     * @param array<string, mixed> $op Operation metadata.
     */
    private function writeOperationDeclaration(
        PhpCodeWriter $writer,
        array $op,
        string $suffix = ''
    ): void
    {
        // A doc block per method - Smithy `@documentation` on the
        // operation is the canonical description. drop it in verbatim
        // after light cleanup so IDEs surface it without any codegen
        // decoration.
        if ($op['documentation'] !== null) {
            $writer->writeDocBlock(
                [$this->cleanDocumentation($op['documentation'])]
            );
        }

        // Compose the parameter text once so both the single-line
        // candidate and the multi-line fallback share the same rules
        // (inputs with a required member are non-nullable; other inputs
        // get the `?...$input = null` defaulted form).
        $parameters = [
            $op['inputRequired']
                ? "{$op['inputShort']} \$input"
                : "?{$op['inputShort']} \$input = null",
            '?RequestOverrideConfig $overrides = null'
        ];

        $this->writeMethodSignature(
            $writer,
            $op['methodName'],
            $parameters,
            $op['outputShort'],
            $suffix
        );
    }

    /**
     * Emit a typed `<operation>Paginated` method.
     *
     * @param array{
     *     methodName: string,
     *     operationShort: string,
     *     operationFqcn: string,
     *     inputShort: string,
     *     inputFqcn: string,
     *     outputShort: string,
     *     outputFqcn: string,
     *     inputRequired: bool,
     *     paginated: bool,
     *     waiters: array<string, array<string, mixed>>,
     *     documentation: ?string,
     * } $op
     *
     * @return void
     */
    private function writePaginatedOperationMethod(
        PhpCodeWriter $writer,
        array $op
    ): void
    {
        $this->writePaginatedOperationDeclaration($writer, $op);
        $writer->openBlock('{');
        $writer->writeLine('return $this->createPaginator(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $writer->writeLine(
            $op['inputRequired']
                ? '$input,'
                : "\$input ?? new {$op['inputShort']}(),"
        );
        $writer->writeLine("{$op['outputShort']}::class");
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Emit a paginator declaration with an optional line suffix.
     *
     * @param array<string, mixed> $op Operation metadata.
     */
    private function writePaginatedOperationDeclaration(
        PhpCodeWriter $writer,
        array $op,
        string $suffix = ''
    ): void
    {
        $writer->writeDocBlock([
            "Create a paginator for {@see self::{$op['methodName']}()}.",
            '',
            "@return Paginator<{$op['outputShort']}>"
        ]);

        $parameters = [
            $op['inputRequired']
                ? "{$op['inputShort']} \$input"
                : "?{$op['inputShort']} \$input = null"
        ];
        $methodName = $op['methodName'] . 'Paginated';

        $this->writeMethodSignature(
            $writer,
            $methodName,
            $parameters,
            'Paginator',
            $suffix
        );
    }

    /**
     * Emit a typed `waitUntil<Name>` method.
     *
     * @param array<string, mixed> $op Operation metadata.
     * @param array<string, mixed> $waiter Normalized waiter metadata.
     *
     * @return void
     */
    private function writeWaiterMethod(
        PhpCodeWriter $writer,
        array $op,
        string $waiterName,
        array $waiter
    ): void
    {
        $this->writeWaiterDeclaration(
            $writer,
            $op,
            $waiterName,
            $waiter
        );
        $writer->openBlock('{');
        $writer->writeLine('return $this->createWaiter(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $writer->writeLine(
            self::renderString($waiterName) . ','
        );
        $writer->writeLine('$config');
        $writer->popIndent();
        $writer->writeLine(')->wait($input, $maxWaitTime);');
        $writer->closeBlock();
    }

    /**
     * Emit a waiter declaration with an optional line suffix.
     *
     * @param array<string, mixed> $op Operation metadata.
     * @param array<string, mixed> $waiter Normalized waiter metadata.
     */
    private function writeWaiterDeclaration(
        PhpCodeWriter $writer,
        array $op,
        string $waiterName,
        array $waiter,
        string $suffix = ''
    ): void
    {
        $documentation = $waiter['documentation'];
        $docLines = [
            $documentation === null
                ? $this->defaultWaiterDocumentation(
                    $op['methodName'],
                    $waiterName
                )
                : $this->cleanDocumentation($documentation)
        ];
        if ($waiter['deprecated']) {
            $docLines[] = '';
            $docLines[] = '@deprecated';
        }
        $docLines[] = '';
        $docLines[] =
            '@param float $maxWaitTime Maximum total wait time in seconds.';
        $docLines[] = '';
        $docLines[] = '@return WaiterResult Terminal output or accepted error.';
        $docLines[] =
            '@throws WaiterFailureException If waiting does not succeed.';
        $docLines[] =
            '@throws \InvalidArgumentException If waiter timing is invalid.';
        $docLines[] = '';
        $docLines[] = "@see self::{$op['methodName']}()";
        $writer->writeDocBlock($docLines);

        $methodName = 'waitUntil' . $waiterName;
        $this->writeMethodSignature(
            $writer,
            $methodName,
            [
                "{$op['inputShort']} \$input",
                'float $maxWaitTime',
                '?WaiterConfig $config = null'
            ],
            'WaiterResult',
            $suffix
        );
    }

    private static function renderString(string $value): string
    {
        return \var_export($value, true);
    }

    private function defaultWaiterDocumentation(
        string $operationMethod,
        string $waiterName
    ): string
    {
        foreach (self::WAITER_STATE_DESCRIPTIONS
            as $suffix => [$resourceSuffix, $state]
        ) {
            if (!\str_ends_with($waiterName, $suffix)) {
                continue;
            }

            $resourceName = \substr(
                $waiterName,
                0,
                -\strlen($suffix)
            );
            if ($resourceName === '') {
                continue;
            }

            $resource = $this->humanizeWaiterResource($resourceName);
            if ($resourceSuffix !== null) {
                $resource .= ' ' . $resourceSuffix;
            }

            return "Wait until {@see self::{$operationMethod}()} reports "
                . "the {$resource} as {$state}.";
        }

        return "Wait until {@see self::{$operationMethod}()} satisfies "
            . "the modeled `{$waiterName}` condition.";
    }

    private function humanizeWaiterResource(string $name): string
    {
        $spaced = \preg_replace(
            '/([A-Z]+)([A-Z][a-z])/',
            '$1 $2',
            $name
        ) ?? $name;
        $spaced = \preg_replace(
            '/([a-z0-9])([A-Z])/',
            '$1 $2',
            $spaced
        ) ?? $spaced;
        $words = \preg_split('/\s+/', $spaced) ?: [$name];

        foreach ($words as &$word) {
            $lower = \strtolower($word);
            $word = $this->settings->waiterResourceInitialisms[$lower]
                ?? $lower;
        }
        unset($word);

        return \implode(' ', $words);
    }

    /**
     * Emit a method signature - single-line when the composed
     * declaration fits within {@see MAX_METHOD_SIGNATURE_WIDTH}, and
     * multi-line otherwise:
     *
     * ```
     * public function fooBar(
     *     FooBarRequest $input,
     *     ?RequestOverrideConfig $overrides = null
     * ): FooBarResponse
     * ```
     *
     * The multi-line branch places the return type on its own line at the
     * method-declaration indent.
     *
     * @param list<string> $parameters
     */
    private function writeMethodSignature(
        PhpCodeWriter $writer,
        string $methodName,
        array $parameters,
        string $returnType,
        string $suffix = ''
    ): void
    {
        $paramText = \implode(', ', $parameters);
        $singleLine =
            "public function {$methodName}({$paramText}): "
                . "{$returnType}{$suffix}";
        if (\strlen(self::METHOD_INDENT . $singleLine)
            <= self::MAX_METHOD_SIGNATURE_WIDTH
        ) {
            $writer->writeLine($singleLine);
            return;
        }

        $writer->writeLine("public function {$methodName}(");
        $writer->pushIndent();
        $last = \count($parameters) - 1;
        foreach ($parameters as $index => $parameter) {
            $writer->writeLine(
                $parameter . ($index === $last ? '' : ',')
            );
        }
        $writer->popIndent();
        $writer->writeLine("): {$returnType}{$suffix}");
    }

    /**
     * Resolve and sort the operations exposed by a generated client.
     *
     * @return list<array<string, mixed>>
     */
    private function operationsForService(
        string $serviceShapeId,
        ServiceProjection $projection
    ): array
    {
        $shape = $this->model->getShape($serviceShapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException(
                "Service not found: {$serviceShapeId}"
            );
        }
        if (($shape['type'] ?? '') !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceShapeId} is not a service"
            );
        }

        $projection->requireService($serviceShapeId);
        $operations = $this->collectOperations(
            $projection->operationIds,
            $this->model->getValidatedServiceWaiters(
                $serviceShapeId,
                $projection->operationIds
            )
        );
        \usort(
            $operations,
            static fn(array $a, array $b): int => \strcmp(
                $a['methodName'],
                $b['methodName']
            )
        );

        return $operations;
    }

    /**
     * Walk the projected operations and resolve each to the tuple
     * the method emitter needs: method name, operation class, input
     * class, output class, and whether the input has a required
     * member.
     *
     * @param list<string> $operationIds
     * @param array<string, array<string, array<string, mixed>>> $waitersByOperation
     * @return list<array{
     *     methodName: string,
     *     operationShort: string,
     *     operationFqcn: string,
     *     inputShort: string,
     *     inputFqcn: string,
     *     outputShort: string,
     *     outputFqcn: string,
     *     inputRequired: bool,
     *     paginated: bool,
     *     waiters: array<string, array<string, mixed>>,
     *     documentation: ?string,
     * }>
     */
    private function collectOperations(
        array $operationIds,
        array $waitersByOperation
    ): array
    {
        $ops = [];
        foreach ($operationIds as $opShapeId) {
            $opShape = $this->model->getShape($opShapeId);
            if ($opShape === null || ($opShape['type'] ?? '') !== 'operation') {
                continue;
            }

            $operationShort =
                SmithyModel::getShapeName($opShapeId) . 'Operation';
            $operationFqcn = $this->settings->resolveOperationsNamespace()
                . '\\'
                . $operationShort;

            $inputShapeId =
                $opShape['input']['target'] ?? 'smithy.api#Unit';
            $outputShapeId =
                $opShape['output']['target'] ?? 'smithy.api#Unit';

            $inputPhpType = $this->symbolProvider->toPhpType($inputShapeId);
            $outputPhpType = $this->symbolProvider->toPhpType($outputShapeId);

            $inputRequired = $this->shapeHasRequiredMembers($inputShapeId);
            $waiters = $waitersByOperation[$opShapeId] ?? [];
            \ksort($waiters, SORT_STRING);

            $ops[] = [
                'methodName' => \lcfirst(SmithyModel::getShapeName($opShapeId)),
                'operationShort' => $operationShort,
                'operationFqcn' => $operationFqcn,
                'inputShort' => $inputPhpType->typeHint,
                'inputFqcn' => $inputPhpType->fqcn ?? '',
                'outputShort' => $outputPhpType->typeHint,
                'outputFqcn' => $outputPhpType->fqcn ?? '',
                'inputRequired' => $inputRequired,
                'paginated' =>
                    $this->model->getValidatedPaginationTrait($opShapeId)
                        !== null,
                'waiters' => $waiters,
                'documentation' => isset(
                    $opShape['traits']['smithy.api#documentation']
                )
                    ? (string) $opShape['traits']['smithy.api#documentation']
                    : null
            ];
        }

        return $ops;
    }

    /**
     * True when the target shape has a member with the `@required` trait.
     * Smithy's `Unit` shape (`smithy.api#Unit`) has no members, so the
     * caller defaults the argument.
     */
    private function shapeHasRequiredMembers(string $shapeId): bool
    {
        if (\str_starts_with($shapeId, 'smithy.api#')) {
            return false;
        }
        foreach ($this->model->getMembers($shapeId) as $member) {
            if (isset($member['traits']['smithy.api#required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive the client name from the configured namespace.
     */
    private function clientShortName(string $serviceShapeId): string
    {
        $parts = \explode('\\', $this->settings->namespace);
        $last = \end($parts);
        if ($last !== false && $last !== '') {
            return $last;
        }
        return SmithyModel::getShapeName($serviceShapeId);
    }

    private function shortName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : \substr($fqcn, $pos + 1);
    }

    private function cleanDocumentation(string $text): string
    {
        $text = \strip_tags($text);
        $text = \preg_replace('/\s+/', ' ', $text) ?? '';
        return \trim($text);
    }
}
