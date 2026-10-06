<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProvider;
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
    private const WAITER_ACCEPTOR = 'Smithy\\Client\\Waiter\\Acceptor';
    private const WAITER_CONFIG = 'Smithy\\Client\\Waiter\\WaiterConfig';
    private const WAITER_FAILURE =
        'Smithy\\Client\\Waiter\\WaiterFailureException';
    private const WAITER_MATCHER = 'Smithy\\Client\\Waiter\\Matcher';
    private const WAITER_PATH_COMPARATOR =
        'Smithy\\Client\\Waiter\\PathComparator';
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
        private readonly PhpSymbolProvider $symbolProvider,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate the client class file for the given service.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID.
     */
    public function generate(string $serviceShapeId): GeneratedFile
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

        $baseClass = $this->settings->clientBaseClass;
        $baseClassShort = $this->shortName($baseClass);

        $shortName = $this->clientShortName($serviceShapeId);
        $className = $shortName . 'Client';
        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        $writer = new PhpCodeWriter($namespace);
        $writer->addImport($baseClass);

        // Sort operations alphabetically by public method name for
        // stable diffs across regenerations and alignment with
        // smithy-java.
        $waitersByOperation =
            $this->model->getValidatedServiceWaiters($serviceShapeId);
        $operations = $this->collectOperations(
            $serviceShapeId,
            $waitersByOperation
        );
        \usort(
            $operations,
            static fn(array $a, array $b): int => \strcmp(
                $a['methodName'],
                $b['methodName']
            )
        );
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
                $writer->addImport(self::WAITER_ACCEPTOR);
                $writer->addImport(self::WAITER_CONFIG);
                $writer->addImport(self::WAITER_FAILURE);
                $writer->addImport(self::WAITER_MATCHER);
                $writer->addImport(self::WAITER_PATH_COMPARATOR);
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
            "final class {$className} extends {$baseClassShort}"
        );
        $writer->openBlock('{');

        $pluginClasses = [];
        foreach ($this->settings->clientPluginsByOperationTrait
            as $traitId => $pluginClass
        ) {
            if ($this->serviceHasOperationTrait(
                    $serviceShapeId,
                    $traitId
                )
            ) {
                $pluginClasses[] = $pluginClass;
            }
        }

        $configClass = '\\' . \ltrim(
            $this->settings->clientConfigClass,
            '\\'
        );
        $pluginCollectionClass = '\\' . self::PLUGIN_COLLECTION;
        foreach ($pluginClasses as $pluginClass) {
            $writer->addImport($pluginClass);
        }
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
        if ($pluginClasses !== []) {
            $writer->writeLine(
                '$plugins = $plugins->merge(new '
                    . $pluginCollectionClass
                    . '('
            );
            $writer->pushIndent();
            $lastPlugin = \count($pluginClasses) - 1;
            foreach ($pluginClasses as $index => $pluginClass) {
                $writer->writeLine(
                    'new '
                    . $this->shortName($pluginClass)
                    . '()'
                    . ($index === $lastPlugin ? '' : ',')
                );
            }
            $writer->popIndent();
            $writer->writeLine('));');
        }
        $writer->writeLine('parent::__construct($config, $plugins);');
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
     *     inputHasMembers: bool,
     *     paginated: bool,
     *     waiters: array<string, array<string, mixed>>,
     *     documentation: ?string,
     * } $op
     *
     * @return void
     */
    private function writeOperationMethod(PhpCodeWriter $writer, array $op): void
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
        // (required inputs are non-nullable. zero-member inputs get the
        // `?...$input = null` defaulted form).
        $parameters = [
            $op['inputHasMembers']
                ? "{$op['inputShort']} \$input"
                : "?{$op['inputShort']} \$input = null",
            '?RequestOverrideConfig $overrides = null'
        ];

        $this->writeMethodSignature(
            $writer,
            $op['methodName'],
            $parameters,
            $op['outputShort']
        );
        $writer->openBlock('{');
        $writer->writeLine("/** @var {$op['outputShort']} */");
        $writer->writeLine('return $this->executeOperation(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $inputArgument = $op['inputHasMembers']
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
     *     inputHasMembers: bool,
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
        $writer->writeDocBlock([
            "Create a paginator for {@see self::{$op['methodName']}()}.",
            '',
            "@return Paginator<{$op['outputShort']}>"
        ]);

        $parameters = [
            $op['inputHasMembers']
                ? "{$op['inputShort']} \$input"
                : "?{$op['inputShort']} \$input = null"
        ];
        $methodName = $op['methodName'] . 'Paginated';

        $this->writeMethodSignature(
            $writer,
            $methodName,
            $parameters,
            'Paginator'
        );
        $writer->openBlock('{');
        $writer->writeLine('return $this->createPaginator(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $writer->writeLine(
            $op['inputHasMembers']
                ? '$input'
                : "\$input ?? new {$op['inputShort']}()"
        );
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
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
        $writer->writeLine("public function {$methodName}(");
        $writer->pushIndent();
        $writer->writeLine("{$op['inputShort']} \$input,");
        $writer->writeLine('float $maxWaitTime,');
        $writer->writeLine('?WaiterConfig $config = null');
        $writer->popIndent();
        $writer->writeLine('): WaiterResult');
        $writer->openBlock('{');
        $writer->writeLine('return $this->createWaiter(');
        $writer->pushIndent();
        $writer->writeLine("{$op['operationShort']}::instance(),");
        $writer->writeLine('[');
        $writer->pushIndent();
        $acceptors = $waiter['acceptors'];
        $lastAcceptor = \count($acceptors) - 1;
        foreach ($acceptors as $index => $acceptor) {
            $this->writeWaiterAcceptor(
                $writer,
                $acceptor,
                $index !== $lastAcceptor
            );
        }
        $writer->popIndent();
        $writer->writeLine('],');
        $writer->writeLine("{$waiter['minDelay']},");
        $writer->writeLine("{$waiter['maxDelay']},");
        $writer->writeLine('$config');
        $writer->popIndent();
        $writer->writeLine(')->wait($input, $maxWaitTime);');
        $writer->closeBlock();
    }

    /**
     * Emit one normalized waiter acceptor.
     *
     * @param array<string, mixed> $acceptor
     *
     * @return void
     */
    private function writeWaiterAcceptor(
        PhpCodeWriter $writer,
        array $acceptor,
        bool $hasNext
    ): void
    {
        $state = match ($acceptor['state']) {
            'success' => 'SUCCESS',
            'failure' => 'FAILURE',
            'retry' => 'RETRY'
        };
        $hasMessage = $acceptor['message'] !== null;

        $writer->writeLine('new Acceptor(');
        $writer->pushIndent();
        $writer->writeLine("Acceptor::{$state},");
        $this->writeWaiterMatcher(
            $writer,
            $acceptor['matcher'],
            $hasMessage
        );
        if ($hasMessage) {
            $writer->writeLine(
                self::renderString($acceptor['message'])
            );
        }
        $writer->popIndent();
        $writer->writeLine($hasNext ? '),' : ')');
    }

    /**
     * Emit one normalized waiter matcher.
     *
     * @param array<string, mixed> $matcher
     *
     * @return void
     */
    private function writeWaiterMatcher(
        PhpCodeWriter $writer,
        array $matcher,
        bool $hasFollowingArgument
    ): void
    {
        $suffix = $hasFollowingArgument ? ',' : '';
        if ($matcher['type'] === 'success') {
            $expected = $matcher['expected'] ? 'true' : 'false';
            $writer->writeLine(
                "Matcher::success({$expected}){$suffix}"
            );

            return;
        }

        if ($matcher['type'] === 'errorType') {
            $expected = self::renderString($matcher['expected']);
            $writer->writeLine(
                "Matcher::errorType({$expected}){$suffix}"
            );

            return;
        }

        $factory = $matcher['type'];
        $comparator = match ($matcher['comparator']) {
            'stringEquals' => 'StringEquals',
            'booleanEquals' => 'BooleanEquals',
            'allStringEquals' => 'AllStringEquals',
            'anyStringEquals' => 'AnyStringEquals'
        };

        $writer->writeLine("Matcher::{$factory}(");
        $writer->pushIndent();
        $writer->writeLine(
            self::renderString($matcher['path']) . ','
        );
        $writer->writeLine(
            self::renderString($matcher['expected']) . ','
        );
        $writer->writeLine("PathComparator::{$comparator}");
        $writer->popIndent();
        $writer->writeLine("){$suffix}");
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
        string $returnType
    ): void
    {
        $paramText = \implode(', ', $parameters);
        $singleLine =
            "public function {$methodName}({$paramText}): {$returnType}";
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
        $writer->writeLine("): {$returnType}");
    }

    /**
     * Walk the service's operation closure and resolve each to the tuple
     * the method emitter needs: method name, operation class, input
     * class, output class, and whether the input has any codegen-
     * emitted members.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID.
     * @return list<array{
     *     methodName: string,
     *     operationShort: string,
     *     operationFqcn: string,
     *     inputShort: string,
     *     inputFqcn: string,
     *     outputShort: string,
     *     outputFqcn: string,
     *     inputHasMembers: bool,
     *     paginated: bool,
     *     waiters: array<string, array<string, mixed>>,
     *     documentation: ?string,
     * }>
     *
     * @param array<string, array<string, array<string, mixed>>>
     *        $waitersByOperation
     */
    private function collectOperations(
        string $serviceShapeId,
        array $waitersByOperation
    ): array
    {
        $ops = [];
        foreach ($this->model->getServiceOperationIds($serviceShapeId)
            as $opShapeId
        ) {
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

            $inputHasMembers = $this->shapeHasMembers($inputShapeId);
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
                'inputHasMembers' => $inputHasMembers,
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

    private function serviceHasOperationTrait(
        string $serviceShapeId,
        string $traitId
    ): bool
    {
        foreach ($this->model->getServiceOperationIds($serviceShapeId)
            as $opShapeId
        ) {
            $opShape = $this->model->getShape($opShapeId);
            if ($opShape === null || ($opShape['type'] ?? '') !== 'operation') {
                continue;
            }

            if (isset(($opShape['traits'] ?? [])[$traitId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the target shape is a structure with at least one
     * emitted member. Smithy's `Unit` shape (`smithy.api#Unit`) is
     * membership-empty by construction, so it lands in the "false"
     * branch and the caller-defaults the argument.
     */
    private function shapeHasMembers(string $shapeId): bool
    {
        if (\str_starts_with($shapeId, 'smithy.api#')) {
            return false;
        }
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            return false;
        }
        $members = $shape['members'] ?? null;
        return \is_array($members) && $members !== [];
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
