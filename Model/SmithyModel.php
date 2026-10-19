<?php

declare(strict_types=1);

namespace Smithy\Codegen\Model;

use Smithy\Client\Waiter\WaiterDefinitionNormalizer;
use Smithy\Codegen\ShapeCategory;
use Smithy\Core\Model\EffectiveAuthSchemes;

/**
 * Represents a parsed Smithy JSON AST model.
 *
 * This reads the JSON output produced by `smithy build` and provides
 * access to shapes, their members, traits, and relationships.
 */
final class SmithyModel
{
    private const RESOURCE_OPERATION_BINDINGS = [
        'create',
        'put',
        'read',
        'update',
        'delete',
        'list'
    ];

    /** @var array<string, array<string, mixed>> Shape ID => shape definition */
    private array $shapes;

    /** @var array<string, mixed> Full model metadata */
    private array $metadata;

    /** @var array<string, string> Shape ID => operation role ('input' or 'output') */
    private array $operationRoles = [];

    /** @var array<string, true> Shape IDs reachable from operation inputs (need fromArray) */
    private array $inputReachable = [];

    /** @var array<string, string> Input shape ID => pagination token member */
    private array $paginationInputTokens = [];

    /** @var array<string, string> Input shape ID => pagination page-size member */
    private array $paginationPageSizeMembers = [];

    private function __construct(array $shapes, array $metadata)
    {
        $this->shapes = $shapes;
        $this->metadata = $metadata;
        $this->indexOperationRoles();
        $this->indexPaginationInputTokens();
        $this->indexInputReachableShapes();
    }

    /**
     * Load a Smithy model from a JSON AST file.
     *
     * @param string $path Path to the JSON AST file
     * @return self
     * @throws \RuntimeException If the file cannot be read or parsed
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Smithy model file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                "Failed to read Smithy model file: {$path}"
            );
        }

        return self::fromJson($content);
    }

    /**
     * Load a Smithy model from a JSON string.
     *
     * @param string $json JSON AST content
     * @return self
     * @throws \RuntimeException If the JSON cannot be parsed
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($data) || \array_is_list($data)) {
            throw new \RuntimeException(
                'Invalid Smithy model: root must be an object'
            );
        }

        if (!isset($data['smithy'])) {
            throw new \RuntimeException(
                'Invalid Smithy model: missing "smithy" version field'
            );
        }

        if (!\is_string($data['smithy']) || $data['smithy'] === '') {
            throw new \RuntimeException(
                'Invalid Smithy model: "smithy" version must be a string'
            );
        }

        SmithyModelValidator::validate($data);

        $shapes = $data['shapes'] ?? [];
        $metadata = $data['metadata'] ?? [];

        if (!\is_array($metadata)) {
            throw new \RuntimeException(
                'Invalid Smithy model: "metadata" must be an object'
            );
        }

        return new self($shapes, $metadata);
    }

    /**
     * Index operation input/output roles so we can categorize shapes.
     *
     * @return void
     */
    private function indexOperationRoles(): void
    {
        foreach ($this->shapes as $shapeId => $shape) {
            if (($shape['type'] ?? '') !== 'operation') {
                continue;
            }
            if (isset($shape['input']['target'])) {
                $target = $shape['input']['target'];
                if (isset($this->operationRoles[$target])
                    && $this->operationRoles[$target] !== 'input'
                ) {
                    $this->operationRoles[$target] = 'both';
                } else {
                    $this->operationRoles[$target] = 'input';
                }
            }
            if (isset($shape['output']['target'])) {
                $target = $shape['output']['target'];
                if (isset($this->operationRoles[$target])
                    && $this->operationRoles[$target] !== 'output'
                ) {
                    $this->operationRoles[$target] = 'both';
                } else {
                    $this->operationRoles[$target] = 'output';
                }
            }
        }
    }

    /**
     * Get all shapes in the model.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getShapes(): array
    {
        return $this->shapes;
    }

    /**
     * Get a specific shape by its fully-qualified ID.
     *
     * @param string $shapeId e.g., "smithy.example#Human"
     * @return array<string, mixed>|null
     */
    public function getShape(string $shapeId): ?array
    {
        return $this->shapes[$shapeId] ?? null;
    }

    /**
     * Prelude shape types for smithy.api# shapes not present in the model.
     */
    private const PRELUDE_TYPES = [
        'smithy.api#String' => 'string',
        'smithy.api#Boolean' => 'boolean',
        'smithy.api#Byte' => 'byte',
        'smithy.api#Short' => 'short',
        'smithy.api#Integer' => 'integer',
        'smithy.api#Long' => 'long',
        'smithy.api#Float' => 'float',
        'smithy.api#Double' => 'double',
        'smithy.api#BigInteger' => 'bigInteger',
        'smithy.api#BigDecimal' => 'bigDecimal',
        'smithy.api#Blob' => 'blob',
        'smithy.api#Timestamp' => 'timestamp',
        'smithy.api#Document' => 'document',
        'smithy.api#Unit' => 'structure',
        'smithy.api#PrimitiveBoolean' => 'boolean',
        'smithy.api#PrimitiveByte' => 'byte',
        'smithy.api#PrimitiveShort' => 'short',
        'smithy.api#PrimitiveInteger' => 'integer',
        'smithy.api#PrimitiveLong' => 'long',
        'smithy.api#PrimitiveFloat' => 'float',
        'smithy.api#PrimitiveDouble' => 'double'
    ];

    /**
     * Get the type of a shape.
     *
     * @param string $shapeId
     * @return string|null e.g., "structure", "string", "enum", "union", etc.
     */
    public function getShapeType(string $shapeId): ?string
    {
        return $this->shapes[$shapeId]['type']
            ?? self::PRELUDE_TYPES[$shapeId]
            ?? null;
    }

    /**
     * Get the members of a structure, union, or enum shape.
     * Resolves mixin members if present.
     *
     * @param string $shapeId
     * @return array<string, array<string, mixed>>
     */
    public function getMembers(string $shapeId): array
    {
        $shape = $this->shapes[$shapeId] ?? null;
        if ($shape === null) {
            return [];
        }

        $members = $shape['members'] ?? [];

        // Resolve mixin members (mixin members come first, local members
        // override)
        if (isset($shape['mixins'])) {
            $mixinMembers = [];
            foreach ($shape['mixins'] as $mixin) {
                $mixinId = $mixin['target'] ?? null;
                if ($mixinId !== null) {
                    $mixinMembers = array_merge(
                        $mixinMembers,
                        $this->getMembers($mixinId)
                    );
                }
            }
            // Local members override mixin members
            $members = array_merge($mixinMembers, $members);
        }

        return $members;
    }

    /**
     * Get traits applied to a shape.
     *
     * @param string $shapeId
     * @return array<string, mixed>
     */
    public function getTraits(string $shapeId): array
    {
        return $this->shapes[$shapeId]['traits'] ?? [];
    }

    /**
     * Return the authentication schemes effective for an operation.
     *
     * @return list<string> Scheme IDs in priority order.
     */
    public function getEffectiveAuthSchemeIds(
        string $serviceId,
        string $operationId
    ): array
    {
        $service = $this->getShape($serviceId);
        if (($service['type'] ?? null) !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceId} is not a service."
            );
        }
        $operation = $this->getShape($operationId);
        if (($operation['type'] ?? null) !== 'operation') {
            throw new \InvalidArgumentException(
                "Shape {$operationId} is not an operation."
            );
        }

        return EffectiveAuthSchemes::resolve(
            $this->shapes,
            $this->getTraits($serviceId),
            $this->getTraits($operationId)
        );
    }

    /**
     * Check if a shape has a specific trait.
     *
     * @param string $shapeId
     * @param string $traitId e.g., "smithy.api#required", "smithy.api#error"
     * @return bool
     */
    public function hasTrait(string $shapeId, string $traitId): bool
    {
        return array_key_exists($traitId, $this->getTraits($shapeId));
    }

    /**
     * Get the value of a specific trait.
     *
     * @param string $shapeId
     * @param string $traitId
     * @return mixed
     */
    public function getTrait(string $shapeId, string $traitId): mixed
    {
        return $this->getTraits($shapeId)[$traitId] ?? null;
    }

    /**
     * Normalize and validate waiters attached to an operation.
     *
     * Waiter and acceptor declaration order is preserved.
     *
     * @return array<string, array<string, mixed>> Waiter name to definition.
     * @throws \InvalidArgumentException If the shape is not an operation.
     * @throws \LogicException If the waitable trait is invalid.
     */
    public function getValidatedWaiters(string $operationShapeId): array
    {
        return WaiterTraitNormalizer::normalizeOperation(
            $this,
            $operationShapeId
        );
    }

    /**
     * Normalize waiters in a service closure and validate unique names.
     *
     * @return array<string, array<string, array<string, mixed>>> Map from
     *                                                            operation shape ID to waiter-name
     *                                                            map.
     * @throws \InvalidArgumentException If the shape is not a service.
     * @throws \LogicException If waiter metadata is invalid.
     */
    public function getValidatedServiceWaiters(
        string $serviceShapeId
    ): array
    {
        $waitersByOperation = [];

        foreach ($this->getServiceOperationIds($serviceShapeId)
            as $operationShapeId
        ) {
            $waiters = $this->getValidatedWaiters($operationShapeId);
            if ($waiters === []) {
                continue;
            }

            $waitersByOperation[$operationShapeId] = $waiters;
        }

        WaiterDefinitionNormalizer::indexServiceWaiters(
            $serviceShapeId,
            $waitersByOperation
        );

        return $waitersByOperation;
    }

    /**
     * Resolve pagination settings for an operation.
     *
     * Service-level pagination settings provide defaults only. An operation
     * must carry its own `@paginated` trait to opt in.
     *
     * @param string $operationShapeId Fully-qualified operation shape ID.
     *
     * @return array<string, string>|null Merged settings, or null when disabled.
     */
    public function getEffectivePaginationTrait(
        string $operationShapeId
    ): ?array
    {
        $operation = $this->getShape($operationShapeId);
        if ($operation === null
            || ($operation['type'] ?? null) !== 'operation'
        ) {
            return null;
        }

        $operationTraits = $operation['traits'] ?? [];
        if (!isset($operationTraits['smithy.api#paginated'])) {
            return null;
        }

        $pagination = [];
        foreach ($this->getServiceShapes() as $serviceId => $service) {
            if (!$this->serviceContainsOperation(
                    $serviceId,
                    $operationShapeId
                )
            ) {
                continue;
            }

            $servicePagination =
                ($service['traits'] ?? [])['smithy.api#paginated'] ?? [];
            if (\is_array($servicePagination)) {
                $pagination = $servicePagination;
            }

            break;
        }

        $operationPagination =
            $operationTraits['smithy.api#paginated'];
        if (\is_array($operationPagination)) {
            $pagination = \array_merge($pagination, $operationPagination);
        }

        return $pagination;
    }

    /**
     * Resolve and validate pagination settings for code generation.
     *
     * @param string $operationShapeId Fully-qualified operation shape ID.
     *
     * @return array<string, string>|null Validated settings, or null when disabled.
     */
    public function getValidatedPaginationTrait(
        string $operationShapeId
    ): ?array
    {
        $pagination =
            $this->getEffectivePaginationTrait($operationShapeId);
        if ($pagination === null) {
            return null;
        }

        $operation = $this->getShape($operationShapeId);
        $inputShapeId = $operation['input']['target'] ?? null;
        $outputShapeId = $operation['output']['target'] ?? null;
        if (!\is_string($inputShapeId) || !\is_string($outputShapeId)) {
            throw new \LogicException(
                "Operation {$operationShapeId} must define input and output "
                . 'shapes to generate a paginator.'
            );
        }

        $inputToken = $this->requirePaginationField(
            $operationShapeId,
            $pagination,
            'inputToken'
        );
        $outputToken = $this->requirePaginationField(
            $operationShapeId,
            $pagination,
            'outputToken'
        );

        $this->validatePaginationInputMember(
            $operationShapeId,
            $inputShapeId,
            'inputToken',
            $inputToken,
            ['string', 'map']
        );
        $this->validatePaginationOutputPath(
            $operationShapeId,
            $outputShapeId,
            'outputToken',
            $outputToken,
            ['string', 'map']
        );

        $pageSize = $pagination['pageSize'] ?? null;
        if ($pageSize !== null) {
            if (!\is_string($pageSize) || $pageSize === '') {
                throw new \LogicException(
                    "Operation {$operationShapeId} pagination metadata "
                    . 'pageSize must be a non-empty string.'
                );
            }

            $this->validatePaginationInputMember(
                $operationShapeId,
                $inputShapeId,
                'pageSize',
                $pageSize,
                ['byte', 'short', 'integer', 'long']
            );
        }

        $items = $pagination['items'] ?? null;
        if ($items !== null) {
            if (!\is_string($items) || $items === '') {
                throw new \LogicException(
                    "Operation {$operationShapeId} pagination metadata "
                    . 'items must be a non-empty string.'
                );
            }

            $this->validatePaginationOutputPath(
                $operationShapeId,
                $outputShapeId,
                'items',
                $items,
                ['list', 'map']
            );
        }

        return $pagination;
    }

    /**
     * Get the pagination token member for an operation input shape.
     */
    public function getPaginationInputTokenMember(
        string $inputShapeId
    ): ?string
    {
        return $this->paginationInputTokens[$inputShapeId] ?? null;
    }

    /**
     * Get the pagination page-size member for an operation input shape.
     */
    public function getPaginationPageSizeMember(
        string $inputShapeId
    ): ?string
    {
        return $this->paginationPageSizeMembers[$inputShapeId] ?? null;
    }

    /**
     * Index pagination token members by operation input shape.
     *
     * @return void
     */
    private function indexPaginationInputTokens(): void
    {
        foreach ($this->getOperationShapes() as $operationId => $operation) {
            $inputShapeId = $operation['input']['target'] ?? null;
            if (!\is_string($inputShapeId)) {
                continue;
            }

            $pagination = $this->getEffectivePaginationTrait($operationId);
            $inputToken = $pagination['inputToken'] ?? null;
            if (!\is_string($inputToken) || $inputToken === '') {
                continue;
            }

            $existingToken =
                $this->paginationInputTokens[$inputShapeId] ?? null;
            if ($existingToken !== null && $existingToken !== $inputToken) {
                throw new \LogicException(
                    "Input shape {$inputShapeId} has conflicting pagination "
                    . "token members: {$existingToken} and {$inputToken}."
                );
            }

            $this->paginationInputTokens[$inputShapeId] = $inputToken;

            $pageSize = $pagination['pageSize'] ?? null;
            if (!\is_string($pageSize) || $pageSize === '') {
                continue;
            }

            $existingPageSize =
                $this->paginationPageSizeMembers[$inputShapeId] ?? null;
            if ($existingPageSize !== null
                && $existingPageSize !== $pageSize
            ) {
                throw new \LogicException(
                    "Input shape {$inputShapeId} has conflicting pagination "
                    . "page-size members: {$existingPageSize} and {$pageSize}."
                );
            }

            $this->paginationPageSizeMembers[$inputShapeId] = $pageSize;
        }
    }

    /**
     * Require a non-empty pagination metadata field.
     *
     * @param array<string, mixed> $pagination
     */
    private function requirePaginationField(
        string $operationShapeId,
        array $pagination,
        string $field
    ): string
    {
        $value = $pagination[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \LogicException(
                "Operation {$operationShapeId} pagination metadata "
                . "{$field} must be a non-empty string."
            );
        }

        return $value;
    }

    /**
     * Validate a top-level pagination input member.
     *
     * @param list<string> $validTypes
     *
     * @return void
     */
    private function validatePaginationInputMember(
        string $operationShapeId,
        string $inputShapeId,
        string $field,
        string $memberName,
        array $validTypes
    ): void
    {
        $target = $this->getMemberTarget($inputShapeId, $memberName);
        if ($target === null) {
            throw new \LogicException(
                "Operation {$operationShapeId} pagination {$field} member "
                . "'{$memberName}' does not exist on {$inputShapeId}."
            );
        }

        $this->validatePaginationTargetType(
            $operationShapeId,
            $field,
            $memberName,
            $target,
            $validTypes
        );
    }

    /**
     * Validate a pagination output member path.
     *
     * @param list<string> $validTypes
     *
     * @return void
     */
    private function validatePaginationOutputPath(
        string $operationShapeId,
        string $outputShapeId,
        string $field,
        string $path,
        array $validTypes
    ): void
    {
        $currentShapeId = $outputShapeId;
        $components = \explode('.', $path);
        $lastIndex = \count($components) - 1;
        $target = null;

        foreach ($components as $index => $component) {
            $target = $this->getMemberTarget(
                $currentShapeId,
                $component
            );
            if ($target === null) {
                throw new \LogicException(
                    "Operation {$operationShapeId} pagination {$field} path "
                    . "'{$path}' does not resolve at '{$component}'."
                );
            }

            if ($index !== $lastIndex) {
                $targetType = $this->getShapeType($target);
                if ($targetType !== 'structure') {
                    throw new \LogicException(
                        "Operation {$operationShapeId} pagination {$field} "
                        . "path '{$path}' cannot traverse '{$component}'."
                    );
                }
                $currentShapeId = $target;
            }
        }

        $this->validatePaginationTargetType(
            $operationShapeId,
            $field,
            $path,
            $target,
            $validTypes
        );
    }

    /**
     * Validate the target type of pagination metadata.
     *
     * @param list<string> $validTypes
     *
     * @return void
     */
    private function validatePaginationTargetType(
        string $operationShapeId,
        string $field,
        string $path,
        ?string $target,
        array $validTypes
    ): void
    {
        $targetType = $target === null
            ? null
            : $this->getShapeType($target);
        if ($targetType === null
            || !\in_array($targetType, $validTypes, true)
        ) {
            $expected = \implode(', ', $validTypes);
            $actual = $targetType ?? 'unknown';

            throw new \LogicException(
                "Operation {$operationShapeId} pagination {$field} "
                . "'{$path}' must target one of: {$expected}; found "
                . "{$actual}."
            );
        }
    }

    /**
     * Whether this shape needs fromArray() - either used directly as an operation input,
     * or transitively reachable from an input shape's member graph.
     *
     * @param string $shapeId
     * @return bool
     */
    public function isUsedAsInput(string $shapeId): bool
    {
        $role = $this->operationRoles[$shapeId] ?? null;
        return $role === 'input' || $role === 'both' || isset(
            $this->inputReachable[$shapeId]
        );
    }

    /**
     * BFS from all operation input shapes to find all structure shapes
     * transitively reachable through member targets. These need fromArray().
     *
     * @return void
     */
    private function indexInputReachableShapes(): void
    {
        $queue = [];

        // Seed with direct operation input shapes
        foreach ($this->operationRoles as $shapeId => $role) {
            if ($role === 'input' || $role === 'both') {
                $queue[] = $shapeId;
            }
        }

        $visited = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $members = $this->getMembers($current);
            foreach ($members as $memberDef) {
                $targetId = $memberDef['target'] ?? null;
                if ($targetId === null || isset($visited[$targetId])) {
                    continue;
                }

                $targetType = $this->getShapeType($targetId);

                if ($targetType === 'structure') {
                    $this->inputReachable[$targetId] = true;
                    $queue[] = $targetId;
                } elseif ($targetType === 'list') {
                    // Walk into the list member's target
                    $listShape = $this->shapes[$targetId] ?? null;
                    $itemTarget = $listShape['member']['target'] ?? null;
                    if ($itemTarget !== null
                        && $this->getShapeType($itemTarget) === 'structure'
                    ) {
                        $this->inputReachable[$itemTarget] = true;
                        $queue[] = $itemTarget;
                    }
                } elseif ($targetType === 'map') {
                    // Walk into the map value's target
                    $mapShape = $this->shapes[$targetId] ?? null;
                    $valueTarget = $mapShape['value']['target'] ?? null;
                    if ($valueTarget !== null
                        && $this->getShapeType($valueTarget) === 'structure'
                    ) {
                        $this->inputReachable[$valueTarget] = true;
                        $queue[] = $valueTarget;
                    }
                } elseif ($targetType === 'union') {
                    // Walk into union members
                    $this->inputReachable[$targetId] = true;
                    $queue[] = $targetId;
                }
            }
        }
    }

    /**
     * Determine the category of a shape for namespace organization.
     *
     * @param string $shapeId
     * @return ShapeCategory
     */
    public function categorizeShape(string $shapeId): ShapeCategory
    {
        // Error shapes
        if ($this->hasTrait($shapeId, 'smithy.api#error')) {
            return ShapeCategory::Error;
        }

        // Input/Output shapes (determined by operation references)
        if (isset($this->operationRoles[$shapeId])) {
            return match ($this->operationRoles[$shapeId]) {
                'input' => ShapeCategory::Input,
                'output' => ShapeCategory::Output,
                default => ShapeCategory::Shape
            };
        }

        return ShapeCategory::Shape;
    }

    /**
     * Get the short name from a fully-qualified shape ID.
     *
     * @param string $shapeId e.g., "smithy.example#Human"
     * @return string e.g., "Human"
     */
    public static function getShapeName(string $shapeId): string
    {
        $parts = explode('#', $shapeId, 2);
        return $parts[1] ?? $parts[0];
    }

    /**
     * Get the namespace from a fully-qualified shape ID.
     *
     * @param string $shapeId e.g., "smithy.example#Human"
     * @return string e.g., "smithy.example"
     */
    public static function getShapeNamespace(string $shapeId): string
    {
        $parts = explode('#', $shapeId, 2);
        return $parts[0];
    }

    /**
     * Get all structure shapes in the model (excluding service, operation, resource shapes).
     *
     * @return array<string, array<string, mixed>> Shape ID => shape definition
     */
    public function getStructureShapes(): array
    {
        return array_filter(
            $this->shapes,
            fn(array $shape) => ($shape['type'] ?? '') === 'structure'
        );
    }

    /**
     * Get all enum shapes in the model (both string enums and intEnums).
     *
     * @return array<string, array<string, mixed>> Shape ID => shape definition
     */
    public function getEnumShapes(): array
    {
        return array_filter(
            $this->shapes,
            fn(array $shape) => in_array(
                $shape['type'] ?? '',
                ['enum', 'intEnum'],
                true
            )
        );
    }

    /**
     * Get all union shapes in the model.
     *
     * @return array<string, array<string, mixed>> Shape ID => shape definition
     */
    public function getUnionShapes(): array
    {
        return array_filter(
            $this->shapes,
            fn(array $shape) => ($shape['type'] ?? '') === 'union'
        );
    }

    /**
     * Get all operation shapes in the model.
     *
     * @return array<string, array<string, mixed>> Shape ID => shape definition
     */
    public function getOperationShapes(): array
    {
        return array_filter(
            $this->shapes,
            fn(array $shape) => ($shape['type'] ?? '') === 'operation'
        );
    }

    /**
     * Get all service shapes in the model.
     *
     * @return array<string, array<string, mixed>> Shape ID => shape definition
     */
    public function getServiceShapes(): array
    {
        return array_filter(
            $this->shapes,
            fn(array $shape) => ($shape['type'] ?? '') === 'service'
        );
    }

    /**
     * Get operations bound within a service closure.
     *
     * @return list<string>
     */
    public function getServiceOperationIds(string $serviceShapeId): array
    {
        $service = $this->getShape($serviceShapeId);
        if ($service === null || ($service['type'] ?? null) !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceShapeId} is not a service."
            );
        }

        $operations = [];
        $seenOperations = [];
        foreach ($service['operations'] ?? [] as $operation) {
            $this->appendOperationReference(
                $operation,
                $operations,
                $seenOperations
            );
        }

        $seenResources = [];
        foreach ($service['resources'] ?? [] as $resource) {
            if (!\is_array($resource)) {
                continue;
            }

            $resourceId = $resource['target'] ?? null;
            if (!\is_string($resourceId)) {
                continue;
            }

            $this->collectResourceOperations(
                $resourceId,
                $operations,
                $seenOperations,
                $seenResources
            );
        }

        return $operations;
    }

    /**
     * Whether a member is optional from a client's perspective.
     *
     * Every top-level operation input member is client-optional. The
     * `@clientOptional` trait extends the same behavior to non-input
     * structures, regardless of `@required` or `@default`.
     */
    public function isMemberClientOptional(
        string $shapeId,
        string $memberName
    ): bool
    {
        $members = $this->getMembers($shapeId);
        if (!isset($members[$memberName])) {
            return false;
        }

        $role = $this->operationRoles[$shapeId] ?? null;
        $shapeTraits = $this->shapes[$shapeId]['traits'] ?? [];
        $memberTraits = $members[$memberName]['traits'] ?? [];

        return $role === 'input'
            || $role === 'both'
            || isset($shapeTraits['smithy.api#input'])
            || isset($memberTraits['smithy.api#clientOptional']);
    }

    /**
     * Check if a member is required (has @required trait and no @default trait).
     *
     * @param string $shapeId Parent shape ID
     * @param string $memberName Member name
     * @return bool
     */
    public function isMemberRequired(string $shapeId, string $memberName): bool
    {
        $members = $this->getMembers($shapeId);
        if (!isset($members[$memberName])
            || $this->isMemberClientOptional($shapeId, $memberName)
        ) {
            return false;
        }

        $memberTraits = $members[$memberName]['traits'] ?? [];
        return isset($memberTraits['smithy.api#required'])
            && !array_key_exists('smithy.api#default', $memberTraits);
    }

    /**
     * Get the raw `@default` trait value for a member.
     *
     * Returns null if the trait is absent OR if the member declares
     * `@default(null)` (which clears any inherited default per the Smithy
     * spec). Use {@see isMemberDefaulted()} to determine whether the value
     * should actually be emitted as a constructor parameter default.
     *
     * @param string $shapeId Parent shape ID
     * @param string $memberName Member name
     * @return mixed
     */
    public function getMemberDefault(string $shapeId, string $memberName): mixed
    {
        $members = $this->getMembers($shapeId);
        $traits = $members[$memberName]['traits'] ?? [];
        return $traits['smithy.api#default'] ?? null;
    }

    /**
     * Whether a member has an effective `@default` value that codegen must
     * materialize.
     *
     * The Smithy `@default` trait selector is restricted to simple types,
     * lists, and maps, so structure and union members can never appear here.
     * Enum and intEnum members go through the same defaulted path: the
     * generator emits a separate `public readonly MyEnum $name.` property
     * declaration and a constructor body that normalizes string/int input
     * via `MyEnum::fromValue()`, so the literal trait value can sit in the
     * constructor body just like any other scalar default.
     *
     * Top-level operation input members and members with `@clientOptional`
     * never qualify in client code. Otherwise, members qualify when the
     * trait is present, the value is not `null` (which clears an inherited
     * default), and the value is supported by the generator.
     *
     * @param string $shapeId Parent shape ID
     * @param string $memberName Member name
     * @return bool
     */
    public function isMemberDefaulted(string $shapeId, string $memberName): bool
    {
        $members = $this->getMembers($shapeId);
        if (!isset($members[$memberName])
            || $this->isMemberClientOptional($shapeId, $memberName)
        ) {
            return false;
        }
        $traits = $members[$memberName]['traits'] ?? [];
        if (!array_key_exists('smithy.api#default', $traits)) {
            return false;
        }
        $value = $traits['smithy.api#default'];
        if ($value === null) {
            return false;
        }
        if (is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value)
        ) {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }
        return false;
    }


    /**
     * Get all members in canonical constructor order.
     *
     * Two ordering modes:
     *
     * **Fresh (no preserved order):** required first, then defaulted, then
     * optional. This is the default for first-time codegen - required
     * members must come first because they have no parameter default,
     * defaulted second so their PHP literal defaults sit adjacent, and
     * pure-optional last with `= null`. PHP allows non-default parameters
     * before defaulted ones but not the other way around.
     *
     * **Preserved (regeneration):** honor the `$preservedOrder` list first,
     * appending any model members not present in that list at the tail
     * (in fresh required-first / defaulted / optional order among the
     * newcomers). This is the ABI-stability path: constructor slots
     * that existed in the prior generation keep their positions
     * regardless of whether their required-ness changed, so positional
     * callers of `new PutObjectRequest($a, $b, $c)` don't silently
     * miscompile. See `Smithy\Codegen\State\CodegenState` for the
     * mechanism that feeds `$preservedOrder` in.
     *
     * A member listed in `$preservedOrder` but no longer present in the
     * model is dropped from the result - the caller (typically
     * `CodegenState` after loading a stale sidecar) is expected to have
     * already validated that removals don't happen (Smithy validators
     * enforce this), so a stale sidecar entry is treated as best-effort
     * and silently ignored rather than causing a hard error.
     *
     * @param string $shapeId Parent shape ID
     * @param list<string>|null $preservedOrder Historical member order
     *                          from a prior generation. `null` on first-time codegen or when
     *                          `CodegenStateMode::Disabled`.
     * @return array<string, array<string, mixed>> Ordered member name -> definition
     */
    public function getMembersOrdered(
        string $shapeId,
        ?array $preservedOrder = null
    ): array
    {
        $members = $this->getMembers($shapeId);

        if ($preservedOrder === null || $preservedOrder === []) {
            // Fresh ordering - historical required-first / defaulted /
            // optional partition. Same as pre-sidecar behavior. every
            // existing test/fixture keeps working.
            $required = [];
            $defaulted = [];
            $optional = [];
            foreach ($members as $name => $def) {
                if ($this->isMemberRequired($shapeId, $name)) {
                    $required[$name] = $def;
                } elseif ($this->isMemberDefaulted($shapeId, $name)) {
                    $defaulted[$name] = $def;
                } else {
                    $optional[$name] = $def;
                }
            }
            return \array_merge($required, $defaulted, $optional);
        }

        // Preserved ordering. Two passes:
        //   1. Walk the preserved list. emit each member that still
        //      exists in the model at its historical slot. Missing
        //      members (stale sidecar entries) are silently dropped.
        //   2. Walk the remaining members that weren't covered in
        //      step 1 - these are new-in-this-generation additions -
        //      and append them in required-first / defaulted /
        //      optional order.
        $result = [];
        $consumed = [];
        foreach ($preservedOrder as $name) {
            if (isset($members[$name])) {
                $result[$name] = $members[$name];
                $consumed[$name] = true;
            }
        }

        $newRequired = [];
        $newDefaulted = [];
        $newOptional = [];
        foreach ($members as $name => $def) {
            if (isset($consumed[$name])) {
                continue;
            }
            if ($this->isMemberRequired($shapeId, $name)) {
                $newRequired[$name] = $def;
            } elseif ($this->isMemberDefaulted($shapeId, $name)) {
                $newDefaulted[$name] = $def;
            } else {
                $newOptional[$name] = $def;
            }
        }

        return \array_merge($result, $newRequired, $newDefaulted, $newOptional);
    }


    /**
     * Get the target shape ID of a member.
     *
     * @param string $shapeId Parent shape ID
     * @param string $memberName Member name
     * @return string|null Target shape ID
     */
    public function getMemberTarget(string $shapeId, string $memberName): ?string
    {
        $members = $this->getMembers($shapeId);
        return $members[$memberName]['target'] ?? null;
    }

    /**
     * Get the model metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check whether a service directly contains an operation.
     *
     * @param array<string, mixed> $service Service shape definition.
     * @param string $operationShapeId Fully-qualified operation shape ID.
     *
     * @return bool True when the operation is directly contained.
     */
    private function serviceContainsOperation(
        string $serviceShapeId,
        string $operationShapeId
    ): bool
    {
        return \in_array(
            $operationShapeId,
            $this->getServiceOperationIds($serviceShapeId),
            true
        );
    }

    /**
     * Add an operation reference once.
     *
     * @param list<string> $operations
     * @param array<string, true> $seenOperations
     *
     * @return void
     */
    private function appendOperationReference(
        mixed $reference,
        array &$operations,
        array &$seenOperations
    ): void
    {
        if (!\is_array($reference)) {
            return;
        }

        $operationId = $reference['target'] ?? null;
        if (!\is_string($operationId)
            || isset($seenOperations[$operationId])
        ) {
            return;
        }

        $operation = $this->getShape($operationId);
        if ($operation === null
            || ($operation['type'] ?? null) !== 'operation'
        ) {
            return;
        }

        $seenOperations[$operationId] = true;
        $operations[] = $operationId;
    }

    /**
     * Collect lifecycle and nested operations from a resource closure.
     *
     * @param list<string> $operations
     * @param array<string, true> $seenOperations
     * @param array<string, true> $seenResources
     *
     * @return void
     */
    private function collectResourceOperations(
        string $resourceId,
        array &$operations,
        array &$seenOperations,
        array &$seenResources
    ): void
    {
        if (isset($seenResources[$resourceId])) {
            return;
        }
        $seenResources[$resourceId] = true;

        $resource = $this->getShape($resourceId);
        if ($resource === null || ($resource['type'] ?? null) !== 'resource') {
            return;
        }

        foreach (self::RESOURCE_OPERATION_BINDINGS as $binding) {
            $this->appendOperationReference(
                $resource[$binding] ?? null,
                $operations,
                $seenOperations
            );
        }

        foreach (['operations', 'collectionOperations'] as $operationList
        ) {
            foreach ($resource[$operationList] ?? [] as $operation) {
                $this->appendOperationReference(
                    $operation,
                    $operations,
                    $seenOperations
                );
            }
        }

        foreach ($resource['resources'] ?? [] as $nestedResource) {
            if (!\is_array($nestedResource)) {
                continue;
            }

            $nestedResourceId = $nestedResource['target'] ?? null;
            if (!\is_string($nestedResourceId)) {
                continue;
            }

            $this->collectResourceOperations(
                $nestedResourceId,
                $operations,
                $seenOperations,
                $seenResources
            );
        }
    }
}
