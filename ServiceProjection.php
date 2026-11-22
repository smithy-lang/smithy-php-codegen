<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

use Smithy\Codegen\Model\SmithyModel;

/**
 * Resolve the service-specific model closure emitted by code generation.
 *
 * A complete projection contains every operation bound to the service. A
 * trimmed projection starts from an explicit operation set and retains only
 * those operations and their transitive shape dependencies.
 */
final readonly class ServiceProjection
{
    /**
     * @param list<string> $operationIds
     * @param array<string, true> $shapeIds
     */
    private function __construct(
        public ?string $serviceShapeId,
        public array $operationIds,
        public array $shapeIds,
        public bool $trimmed,
        public bool $hasErrors,
        public bool $usesUnit
    ) {}

    /**
     * Resolve a complete or trimmed projection from a model.
     *
     * @param list<string>|null $operationSet Operation names or shape IDs.
     */
    public static function fromModel(
        SmithyModel $model,
        ?array $operationSet
    ): self
    {
        $serviceIds = [];
        foreach ($model->getServiceShapes() as $shapeId => $_) {
            if (!\str_starts_with($shapeId, 'smithy.api#')) {
                $serviceIds[] = $shapeId;
            }
        }

        if (\count($serviceIds) > 1) {
            throw new \InvalidArgumentException(
                'Code generation requires at most one service shape; found: '
                    . \implode(', ', $serviceIds)
            );
        }

        $serviceShapeId = $serviceIds[0] ?? null;
        if ($serviceShapeId === null) {
            if ($operationSet !== null) {
                throw new \InvalidArgumentException(
                    'operationSet requires a model with a service shape'
                );
            }

            $seeds = [];
            foreach ($model->getShapes() as $shapeId => $_) {
                if (!\str_starts_with($shapeId, 'smithy.api#')) {
                    $seeds[] = $shapeId;
                }
            }

            $shapeIds = self::buildShapeClosure($model, $seeds);

            return new self(
                serviceShapeId: null,
                operationIds: [],
                shapeIds: $shapeIds,
                trimmed: false,
                hasErrors: self::containsErrors($model, $shapeIds),
                usesUnit: self::projectionUsesUnit(
                    $model,
                    \array_keys($model->getOperationShapes()),
                    $shapeIds
                )
            );
        }

        $availableOperationIds =
            $model->getServiceOperationIds($serviceShapeId);
        $operationIds = self::resolveOperationIds(
            $serviceShapeId,
            $availableOperationIds,
            $operationSet
        );
        $shapeIds = self::buildServiceShapeClosure(
            $model,
            $serviceShapeId,
            $operationIds
        );

        return new self(
            serviceShapeId: $serviceShapeId,
            operationIds: $operationIds,
            shapeIds: $shapeIds,
            trimmed: $operationSet !== null,
            hasErrors: self::containsErrors($model, $shapeIds),
            usesUnit: self::projectionUsesUnit(
                $model,
                $operationIds,
                $shapeIds
            )
        );
    }

    /**
     * Assert that this projection belongs to the requested service.
     */
    public function requireService(string $serviceShapeId): void
    {
        if ($this->serviceShapeId !== $serviceShapeId) {
            throw new \InvalidArgumentException(
                "Service projection does not describe {$serviceShapeId}"
            );
        }
    }

    /**
     * @param list<string> $availableOperationIds
     * @param list<string>|null $operationSet
     *
     * @return list<string>
     */
    private static function resolveOperationIds(
        string $serviceShapeId,
        array $availableOperationIds,
        ?array $operationSet
    ): array
    {
        if ($operationSet === null) {
            return $availableOperationIds;
        }

        $idsByName = [];
        foreach ($availableOperationIds as $operationId) {
            $idsByName[SmithyModel::getShapeName($operationId)][] =
                $operationId;
        }

        $selected = [];
        foreach ($operationSet as $operation) {
            if (\str_contains($operation, '#')) {
                $operationId = \in_array(
                    $operation,
                    $availableOperationIds,
                    true
                )
                    ? $operation
                    : null;
            } else {
                $matches = $idsByName[$operation] ?? [];
                if (\count($matches) > 1) {
                    throw new \InvalidArgumentException(
                        "operationSet entry {$operation} is ambiguous; use "
                            . 'a fully-qualified shape ID'
                    );
                }
                $operationId = $matches[0] ?? null;
            }

            if ($operationId === null) {
                $available = \array_keys($idsByName);
                \sort($available, SORT_STRING);
                throw new \InvalidArgumentException(
                    "Operation {$operation} is not bound to service "
                        . "{$serviceShapeId}. Available operations: "
                        . \implode(', ', $available)
                );
            }
            if (isset($selected[$operationId])) {
                throw new \InvalidArgumentException(
                    "operationSet selects {$operationId} more than once"
                );
            }

            $selected[$operationId] = true;
        }

        return \array_values(\array_filter(
            $availableOperationIds,
            static fn(string $operationId): bool =>
                isset($selected[$operationId])
        ));
    }

    /**
     * @param list<string> $operationIds
     *
     * @return array<string, true>
     */
    private static function buildServiceShapeClosure(
        SmithyModel $model,
        string $serviceShapeId,
        array $operationIds
    ): array
    {
        $seeds = [$serviceShapeId, ...$operationIds];
        $service = $model->getShape($serviceShapeId);
        foreach ($service['errors'] ?? [] as $error) {
            $target = $error['target'] ?? null;
            if (\is_string($target)) {
                $seeds[] = $target;
            }
        }

        return self::buildShapeClosure($model, $seeds);
    }

    /**
     * @param array<string, true> $shapeIds
     */
    private static function containsErrors(
        SmithyModel $model,
        array $shapeIds
    ): bool
    {
        foreach ($shapeIds as $shapeId => $_) {
            if ($model->hasTrait($shapeId, 'smithy.api#error')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $seeds
     *
     * @return array<string, true>
     */
    private static function buildShapeClosure(
        SmithyModel $model,
        array $seeds
    ): array
    {
        $closure = [];
        $queue = $seeds;

        while ($queue !== []) {
            $shapeId = \array_pop($queue);
            if (isset($closure[$shapeId])
                || \str_starts_with($shapeId, 'smithy.api#')
            ) {
                continue;
            }

            $closure[$shapeId] = true;
            $shape = $model->getShape($shapeId);
            if ($shape === null) {
                continue;
            }

            $type = $shape['type'] ?? '';
            if ($type === 'operation') {
                foreach (['input', 'output'] as $binding) {
                    $target = $shape[$binding]['target'] ?? null;
                    if (\is_string($target)) {
                        $queue[] = $target;
                    }
                }
                foreach ($shape['errors'] ?? [] as $error) {
                    $target = $error['target'] ?? null;
                    if (\is_string($target)) {
                        $queue[] = $target;
                    }
                }
            }

            if ($type === 'structure' || $type === 'union') {
                foreach ($shape['members'] ?? [] as $member) {
                    $target = $member['target'] ?? null;
                    if (\is_string($target)) {
                        $queue[] = $target;
                    }
                }
                foreach ($shape['mixins'] ?? [] as $mixin) {
                    $target = $mixin['target'] ?? null;
                    if (\is_string($target)) {
                        $queue[] = $target;
                    }
                }
            }

            if ($type === 'list') {
                $target = $shape['member']['target'] ?? null;
                if (\is_string($target)) {
                    $queue[] = $target;
                }
            }

            if ($type === 'map') {
                foreach (['key', 'value'] as $binding) {
                    $target = $shape[$binding]['target'] ?? null;
                    if (\is_string($target)) {
                        $queue[] = $target;
                    }
                }
            }
        }

        return $closure;
    }

    /**
     * @param list<string> $operationIds
     * @param array<string, true> $shapeIds
     */
    private static function projectionUsesUnit(
        SmithyModel $model,
        array $operationIds,
        array $shapeIds
    ): bool
    {
        if (self::operationsUseUnit($model, $operationIds)) {
            return true;
        }

        foreach ($shapeIds as $shapeId => $_) {
            $shape = $model->getShape($shapeId);
            if ($shape !== null && self::shapeUsesUnit($shape)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $shape
     */
    private static function shapeUsesUnit(array $shape): bool
    {
        $type = $shape['type'] ?? null;
        if ($type === 'structure' || $type === 'union') {
            foreach ($shape['members'] ?? [] as $member) {
                if (($member['target'] ?? null) === 'smithy.api#Unit') {
                    return true;
                }
            }
        } elseif ($type === 'list') {
            return ($shape['member']['target'] ?? null)
                === 'smithy.api#Unit';
        } elseif ($type === 'map') {
            return ($shape['key']['target'] ?? null) === 'smithy.api#Unit'
                || ($shape['value']['target'] ?? null)
                    === 'smithy.api#Unit';
        }

        return false;
    }

    /**
     * @param list<string> $operationIds
     */
    private static function operationsUseUnit(
        SmithyModel $model,
        array $operationIds
    ): bool
    {
        foreach ($operationIds as $operationId) {
            $operation = $model->getShape($operationId);
            if ($operation === null
                || ($operation['type'] ?? null) !== 'operation'
            ) {
                continue;
            }

            $input = $operation['input']['target'] ?? null;
            $output = $operation['output']['target'] ?? null;
            if ($input === null
                || $output === null
                || $input === 'smithy.api#Unit'
                || $output === 'smithy.api#Unit'
            ) {
                return true;
            }
        }

        return false;
    }
}
