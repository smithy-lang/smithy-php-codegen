<?php

declare(strict_types=1);

namespace Smithy\Codegen;

use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;

/**
 * Configure PHP code generation.
 */
final class CodegenSettings
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const CLASS_NAME_PATTERN =
        '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';

    private const ORGANIZATIONS = [
        'categorized',
        'flat'
    ];

    private const ARRAY_ACCESS_CATEGORIES = [
        'all',
        'error',
        'input',
        'output',
        'shape'
    ];

    /**
     * @param array{
     *     input: string,
     *     output: string,
     *     shapes: string,
     *     errors: string,
     *     operations: string,
     *     endpoint: string
     * } $subNamespaces
     * @param array{enabled: bool, categories: list<string>} $arrayAccess
     * @param array<string, RulesFunctionInterface> $rulesFunctions
     * @param array<string, int> $extendedOpcodes
     * @param array<string, array<string, string>> $operationConstants
     * @param array<string, string> $clientPluginsByOperationTrait
     * @param array<string, array<string, string>> $shapeConstants
     * @param array<string, list<string>> $errorDiscriminators
     * @param array<string, int> $schemaTraitIndices
     * @param array<string, array<int|string, mixed>> $operationSchemaTraits
     * @param array<string, string> $waiterResourceInitialisms
     */
    public function __construct(
        public readonly string $namespace,
        public readonly string $outputDir,
        public readonly string $organization = 'categorized',
        public readonly array $subNamespaces = [
            'input' => 'Input',
            'output' => 'Output',
            'shapes' => 'Shape',
            'errors' => 'Exception',
            'operations' => 'Operation',
            'endpoint' => 'Endpoint'
        ],
        public readonly array $arrayAccess = [
            'enabled' => false,
            'categories' => ['output']
        ],
        public readonly ?string $baseServiceException = null,
        public readonly ?string $serviceExceptionClass = null,
        public readonly array $rulesFunctions = [],
        public readonly array $extendedOpcodes = [],
        public readonly bool $preserveExistingMemberOrder = true,
        public readonly bool $generateEndpointKeyBuilder = false,
        public readonly array $operationConstants = [],
        public readonly string $clientBaseClass =
            'Smithy\\Client\\AbstractClient',
        public readonly array $clientPluginsByOperationTrait = [
            'smithy.api#requestCompression' =>
                'Smithy\\Client\\Plugin\\RequestCompressionPlugin',
            'smithy.api#httpChecksumRequired' =>
                'Smithy\\Client\\Plugin\\HttpChecksumPlugin'
        ],
        public readonly bool $generateClient = true,
        public readonly bool $generateClientConfig = true,
        public readonly array $shapeConstants = [],
        public readonly array $errorDiscriminators = [],
        public readonly array $schemaTraitIndices = [],
        public readonly array $operationSchemaTraits = [],
        public readonly array $waiterResourceInitialisms = [],
        public readonly bool $assumeAsciiEndpointStrings = false
    ) {
        $this->validate();
    }

    /**
     * Create settings from a decoded configuration object.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        self::rejectUnknownKeys($config);

        return new self(
            namespace: $config['namespace']
                ?? throw new \InvalidArgumentException(
                    'namespace is required'
                ),
            outputDir: $config['outputDir']
                ?? throw new \InvalidArgumentException(
                    'outputDir is required'
                ),
            organization: $config['organization'] ?? 'categorized',
            subNamespaces: \array_merge(
                [
                    'input' => 'Input',
                    'output' => 'Output',
                    'shapes' => 'Shape',
                    'errors' => 'Exception',
                    'operations' => 'Operation',
                    'endpoint' => 'Endpoint'
                ],
                $config['subNamespaces'] ?? []
            ),
            arrayAccess: \array_merge(
                [
                    'enabled' => false,
                    'categories' => ['output']
                ],
                $config['arrayAccess'] ?? []
            ),
            baseServiceException: $config['baseServiceException'] ?? null,
            serviceExceptionClass: $config['serviceExceptionClass'] ?? null,
            rulesFunctions: $config['rulesFunctions'] ?? [],
            extendedOpcodes: $config['extendedOpcodes'] ?? [],
            preserveExistingMemberOrder: self::booleanAliasedValue(
                $config,
                'preserveExistingMemberOrder',
                'preserve_existing_member_order',
                true
            ),
            generateEndpointKeyBuilder: self::booleanAliasedValue(
                $config,
                'generateEndpointKeyBuilder',
                'generate_endpoint_key_builder',
                false
            ),
            operationConstants: self::aliasedValue(
                $config,
                'operationConstants',
                'operation_constants',
                []
            ),
            clientBaseClass: self::aliasedValue(
                $config,
                'clientBaseClass',
                'client_base_class',
                'Smithy\\Client\\AbstractClient'
            ),
            clientPluginsByOperationTrait: self::aliasedValue(
                $config,
                'clientPluginsByOperationTrait',
                'client_plugins_by_operation_trait',
                [
                    'smithy.api#requestCompression' =>
                        'Smithy\\Client\\Plugin\\RequestCompressionPlugin',
                    'smithy.api#httpChecksumRequired' =>
                        'Smithy\\Client\\Plugin\\HttpChecksumPlugin'
                ]
            ),
            generateClient: self::booleanAliasedValue(
                $config,
                'generateClient',
                'generate_client',
                true
            ),
            generateClientConfig: self::booleanAliasedValue(
                $config,
                'generateClientConfig',
                'generate_client_config',
                true
            ),
            shapeConstants: self::aliasedValue(
                $config,
                'shapeConstants',
                'shape_constants',
                []
            ),
            errorDiscriminators: self::aliasedValue(
                $config,
                'errorDiscriminators',
                'error_discriminators',
                []
            ),
            schemaTraitIndices: self::aliasedValue(
                $config,
                'schemaTraitIndices',
                'schema_trait_indices',
                []
            ),
            operationSchemaTraits: self::normalizeOperationSchemaTraits(
                self::aliasedValue(
                    $config,
                    'operationSchemaTraits',
                    'operation_schema_traits',
                    []
                )
            ),
            waiterResourceInitialisms: self::aliasedValue(
                $config,
                'waiterResourceInitialisms',
                'waiter_resource_initialisms',
                []
            ),
            assumeAsciiEndpointStrings: self::booleanAliasedValue(
                $config,
                'assumeAsciiEndpointStrings',
                'assume_ascii_endpoint_strings',
                false
            )
        );
    }

    /**
     * Resolve the namespace for a shape category.
     */
    public function resolveNamespace(ShapeCategory $category): string
    {
        if ($this->organization === 'flat') {
            return $this->namespace;
        }

        $subNamespace = match ($category) {
            ShapeCategory::Input => $this->subNamespaces['input'],
            ShapeCategory::Output => $this->subNamespaces['output'],
            ShapeCategory::Error => $this->subNamespaces['errors'],
            ShapeCategory::Shape => $this->subNamespaces['shapes']
        };

        return $this->namespace . '\\' . $subNamespace;
    }

    /**
     * Resolve the output directory for a shape category.
     */
    public function resolveOutputDir(ShapeCategory $category): string
    {
        if ($this->organization === 'flat') {
            return $this->outputDir;
        }

        $subDirectory = match ($category) {
            ShapeCategory::Input => $this->subNamespaces['input'],
            ShapeCategory::Output => $this->subNamespaces['output'],
            ShapeCategory::Error => $this->subNamespaces['errors'],
            ShapeCategory::Shape => $this->subNamespaces['shapes']
        };

        return $this->outputDir . DIRECTORY_SEPARATOR . $subDirectory;
    }

    /**
     * Resolve the operation namespace.
     */
    public function resolveOperationsNamespace(): string
    {
        if ($this->organization === 'flat') {
            return $this->namespace;
        }

        return $this->namespace
            . '\\'
            . $this->subNamespaces['operations'];
    }

    /**
     * Resolve the operation output directory.
     */
    public function resolveOperationsOutputDir(): string
    {
        if ($this->organization === 'flat') {
            return $this->outputDir;
        }

        return $this->outputDir
            . DIRECTORY_SEPARATOR
            . $this->subNamespaces['operations'];
    }

    /**
     * Resolve the endpoint artifact namespace.
     */
    public function resolveEndpointNamespace(): string
    {
        if ($this->organization === 'flat') {
            return $this->namespace;
        }

        return $this->namespace
            . '\\'
            . $this->subNamespaces['endpoint'];
    }

    /**
     * Resolve the endpoint artifact output directory.
     */
    public function resolveEndpointOutputDir(): string
    {
        if ($this->organization === 'flat') {
            return $this->outputDir;
        }

        return $this->outputDir
            . DIRECTORY_SEPARATOR
            . $this->subNamespaces['endpoint'];
    }

    /**
     * Check whether a shape category implements ArrayAccess.
     */
    public function shouldGenerateArrayAccess(ShapeCategory $category): bool
    {
        if (!$this->arrayAccess['enabled']) {
            return false;
        }

        $categoryName = \strtolower($category->name);

        return \in_array('all', $this->arrayAccess['categories'], true)
            || \in_array(
                $categoryName,
                $this->arrayAccess['categories'],
                true
            );
    }

    /**
     * Validate all settings.
     *
     * @return void
     */
    private function validate(): void
    {
        self::requireNonEmptyString($this->namespace, 'namespace');
        self::requireNonEmptyString($this->outputDir, 'outputDir');
        self::requireClassName($this->namespace, 'namespace', false);

        if (!\in_array($this->organization, self::ORGANIZATIONS, true)) {
            throw new \InvalidArgumentException(
                'organization must be "categorized" or "flat"'
            );
        }

        $this->validateSubNamespaces();
        $this->validateArrayAccess();
        $this->validateNullableClass(
            $this->baseServiceException,
            'baseServiceException'
        );
        $this->validateNullableClass(
            $this->serviceExceptionClass,
            'serviceExceptionClass'
        );
        self::requireClassName(
            $this->clientBaseClass,
            'clientBaseClass'
        );
        $this->validateRulesFunctions();
        $this->validateExtendedOpcodes();
        $this->validateConstantMaps(
            $this->operationConstants,
            'operationConstants'
        );
        $this->validateStringMap(
            $this->clientPluginsByOperationTrait,
            'clientPluginsByOperationTrait'
        );
        $this->validateConstantMaps(
            $this->shapeConstants,
            'shapeConstants'
        );
        $this->validateErrorDiscriminators();
        $this->validateSchemaTraitIndices();
        $this->validateOperationSchemaTraits();
        $this->validateWaiterResourceInitialisms();
    }

    /**
     * @return void
     */
    private function validateSubNamespaces(): void
    {
        $required = [
            'input',
            'output',
            'shapes',
            'errors',
            'operations',
            'endpoint'
        ];
        $unknown = \array_diff(\array_keys($this->subNamespaces), $required);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown subNamespace: ' . \reset($unknown)
            );
        }
        foreach ($required as $key) {
            $value = $this->subNamespaces[$key] ?? null;
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException(
                    "subNamespaces.{$key} must be a non-empty string"
                );
            }
            self::requireClassName(
                $value,
                "subNamespaces.{$key}",
                false
            );
        }
    }

    /**
     * @return void
     */
    private function validateArrayAccess(): void
    {
        $unknown = \array_diff(
            \array_keys($this->arrayAccess),
            [
                'enabled',
                'categories'
            ]
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown arrayAccess setting: ' . \reset($unknown)
            );
        }
        if (!isset($this->arrayAccess['enabled'])
            || !\is_bool($this->arrayAccess['enabled'])
        ) {
            throw new \InvalidArgumentException(
                'arrayAccess.enabled must be a bool'
            );
        }

        $categories = $this->arrayAccess['categories'] ?? null;
        if (!\is_array($categories) || !\array_is_list($categories)) {
            throw new \InvalidArgumentException(
                'arrayAccess.categories must be a list'
            );
        }

        foreach ($categories as $category) {
            if (!\is_string($category)
                || !\in_array(
                    $category,
                    self::ARRAY_ACCESS_CATEGORIES,
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    'arrayAccess.categories contains an invalid category'
                );
            }
        }
    }

    /**
     * @return void
     */
    private function validateRulesFunctions(): void
    {
        foreach ($this->rulesFunctions as $name => $function) {
            if (!$function instanceof RulesFunctionInterface) {
                throw new \InvalidArgumentException(
                    'rulesFunctions must contain RulesFunctionInterface '
                        . "instances, got {$name}"
                );
            }
        }
    }

    /**
     * @return void
     */
    private function validateExtendedOpcodes(): void
    {
        foreach ($this->extendedOpcodes as $name => $opcode) {
            if (!\is_string($name)
                || $name === ''
                || !\is_int($opcode)
                || $opcode < 0
                || $opcode > 255
            ) {
                throw new \InvalidArgumentException(
                    'extendedOpcodes must map names to byte values'
                );
            }
        }
    }

    /**
     * @return void
     */
    private function validateWaiterResourceInitialisms(): void
    {
        foreach ($this->waiterResourceInitialisms as $word => $initialism) {
            if (!\is_string($word)
                || \preg_match('/^[a-z0-9]+$/D', $word) !== 1
                || !\is_string($initialism)
                || $initialism === ''
            ) {
                throw new \InvalidArgumentException(
                    'waiterResourceInitialisms must map lowercase words '
                        . 'to non-empty strings'
                );
            }
        }
    }

    /**
     * @param array<string, string> $values
     *
     * @return void
     */
    private function validateStringMap(array $values, string $name): void
    {
        foreach ($values as $key => $value) {
            if (!\is_string($key)
                || $key === ''
                || !\is_string($value)
                || $value === ''
            ) {
                throw new \InvalidArgumentException(
                    "{$name} must map non-empty strings to non-empty strings"
                );
            }
            self::requireClassName($value, "{$name}.{$key}");
        }
    }

    /**
     * @param array<string, array<string, string>> $values
     *
     * @return void
     */
    private function validateConstantMaps(
        array $values,
        string $name
    ): void
    {
        foreach ($values as $shapeId => $map) {
            if (!\is_string($shapeId)
                || $shapeId === ''
                || !\is_array($map)
            ) {
                throw new \InvalidArgumentException(
                    "{$name} must be keyed by shape ID"
                );
            }
            foreach ($map as $constant => $value) {
                if (!\is_string($constant)
                    || !\preg_match(
                        self::IDENTIFIER_PATTERN,
                        $constant
                    )
                    || !\is_string($value)
                ) {
                    throw new \InvalidArgumentException(
                        "{$name}.{$shapeId} must map constant names "
                            . 'to strings'
                    );
                }
            }
        }
    }

    /**
     * @return void
     */
    private function validateErrorDiscriminators(): void
    {
        foreach ($this->errorDiscriminators as $shapeId => $values) {
            if (!\is_string($shapeId)
                || $shapeId === ''
                || !\is_array($values)
                || !\array_is_list($values)
            ) {
                throw new \InvalidArgumentException(
                    'errorDiscriminators must map shape IDs to lists'
                );
            }
            foreach ($values as $value) {
                if (!\is_string($value) || $value === '') {
                    throw new \InvalidArgumentException(
                        'errorDiscriminators must contain non-empty strings'
                    );
                }
            }
        }
    }

    /**
     * @return void
     */
    private function validateSchemaTraitIndices(): void
    {
        foreach ($this->schemaTraitIndices as $traitId => $index) {
            if (!\is_string($traitId)
                || $traitId === ''
                || !\is_int($index)
                || $index < 0
            ) {
                throw new \InvalidArgumentException(
                    'schemaTraitIndices must map trait IDs to indexes'
                );
            }
        }
    }

    /**
     * @return void
     */
    private function validateOperationSchemaTraits(): void
    {
        foreach ($this->operationSchemaTraits as $shapeId => $traits) {
            if (!\is_string($shapeId)
                || $shapeId === ''
                || !\is_array($traits)
            ) {
                throw new \InvalidArgumentException(
                    'operationSchemaTraits must be keyed by shape ID'
                );
            }
            foreach ($traits as $index => $value) {
                if (\is_int($index) && $index >= 0) {
                    self::validateLiteralValue(
                        $value,
                        'operationSchemaTraits'
                    );
                    continue;
                }
                if (!\is_string($index)
                    || $index === ''
                    || !isset($this->schemaTraitIndices[$index])
                ) {
                    throw new \InvalidArgumentException(
                        'operationSchemaTraits must use non-negative integer '
                            . 'indexes or trait IDs configured in '
                            . 'schemaTraitIndices'
                    );
                }
                self::validateLiteralValue(
                    $value,
                    'operationSchemaTraits'
                );
            }
        }
    }

    private function validateNullableClass(
        ?string $value,
        string $name
    ): void
    {
        if ($value !== null) {
            self::requireClassName($value, $name);
        }
    }

    private static function requireNonEmptyString(
        mixed $value,
        string $name
    ): void
    {
        if (!\is_string($value) || \trim($value) === '') {
            throw new \InvalidArgumentException(
                "{$name} must be a non-empty string"
            );
        }
    }

    private static function requireClassName(
        mixed $value,
        string $name,
        bool $allowLeadingSlash = true
    ): void
    {
        self::requireNonEmptyString($value, $name);
        $pattern = $allowLeadingSlash
            ? self::CLASS_NAME_PATTERN
            : '/^[A-Za-z_][A-Za-z0-9_]*'
                . '(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';
        if (!\preg_match($pattern, $value)) {
            throw new \InvalidArgumentException(
                "{$name} must be a valid PHP class name"
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function rejectUnknownKeys(array $config): void
    {
        $known = [
            'namespace',
            'outputDir',
            'organization',
            'subNamespaces',
            'arrayAccess',
            'baseServiceException',
            'serviceExceptionClass',
            'rulesFunctions',
            'extendedOpcodes',
            'preserveExistingMemberOrder',
            'preserve_existing_member_order',
            'generateEndpointKeyBuilder',
            'generate_endpoint_key_builder',
            'operationConstants',
            'operation_constants',
            'clientBaseClass',
            'client_base_class',
            'clientPluginsByOperationTrait',
            'client_plugins_by_operation_trait',
            'generateClient',
            'generate_client',
            'generateClientConfig',
            'generate_client_config',
            'shapeConstants',
            'shape_constants',
            'errorDiscriminators',
            'error_discriminators',
            'schemaTraitIndices',
            'schema_trait_indices',
            'operationSchemaTraits',
            'operation_schema_traits',
            'waiterResourceInitialisms',
            'waiter_resource_initialisms',
            'assumeAsciiEndpointStrings',
            'assume_ascii_endpoint_strings'
        ];
        $unknown = \array_diff(\array_keys($config), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown codegen setting: ' . \reset($unknown)
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function aliasedValue(
        array $config,
        string $camelCase,
        string $snakeCase,
        mixed $default
    ): mixed
    {
        if (isset($config[$camelCase])
            && isset($config[$snakeCase])
        ) {
            throw new \InvalidArgumentException(
                "Use either {$camelCase} or {$snakeCase}, not both"
            );
        }

        return $config[$camelCase] ?? $config[$snakeCase] ?? $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function booleanAliasedValue(
        array $config,
        string $camelCase,
        string $snakeCase,
        bool $default
    ): bool
    {
        $value = self::aliasedValue(
            $config,
            $camelCase,
            $snakeCase,
            $default
        );
        if (!\is_bool($value)) {
            throw new \InvalidArgumentException(
                "{$camelCase} must be a bool"
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $traits
     * @return array<string, array<int|string, mixed>>
     */
    private static function normalizeOperationSchemaTraits(
        array $traits
    ): array
    {
        $normalized = [];
        foreach ($traits as $shapeId => $values) {
            if (!\is_array($values)) {
                $normalized[$shapeId] = $values;
                continue;
            }
            foreach ($values as $index => $value) {
                if (\is_int($index)) {
                    $normalized[$shapeId][$index] = $value;
                    continue;
                }
                if (!\is_string($index) || $index === '') {
                    throw new \InvalidArgumentException(
                        'operationSchemaTraits must use non-negative integer '
                            . 'indexes or non-empty trait IDs'
                    );
                }
                $normalizedIndex = \ctype_digit($index)
                    ? (int) $index
                    : $index;
                $normalized[$shapeId][$normalizedIndex] = $value;
            }
        }

        return $normalized;
    }

    private static function validateLiteralValue(
        mixed $value,
        string $name
    ): void
    {
        if ($value === null
            || \is_bool($value)
            || \is_int($value)
            || \is_float($value)
            || \is_string($value)
        ) {
            return;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(
                "{$name} contains an unsupported value"
            );
        }
        foreach ($value as $key => $member) {
            if (!\is_int($key) && !\is_string($key)) {
                throw new \InvalidArgumentException(
                    "{$name} contains an unsupported array key"
                );
            }
            self::validateLiteralValue($member, $name);
        }
    }
}
