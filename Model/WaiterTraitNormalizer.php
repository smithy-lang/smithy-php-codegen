<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Model;

use Smithy\Client\Waiter\WaiterDefinitionNormalizer;

/**
 * Normalize `smithy.waiters#waitable` into a codegen-ready structure.
 *
 * @internal
 */
final class WaiterTraitNormalizer
{
    private const TRAIT = 'smithy.waiters#waitable';

    private function __construct() {}

    /**
     * Normalize waiters attached to an operation.
     *
     * @return array<string, array<string, mixed>>
     * @throws \InvalidArgumentException If the shape is not an operation.
     * @throws \LogicException If the waitable trait is invalid.
     */
    public static function normalizeOperation(
        SmithyModel $model,
        string $operationShapeId
    ): array
    {
        $operation = $model->getShape($operationShapeId);
        if ($operation === null
            || ($operation['type'] ?? null) !== 'operation'
        ) {
            throw new \InvalidArgumentException(
                "Shape {$operationShapeId} is not an operation."
            );
        }

        $traits = $operation['traits'] ?? [];
        if (!isset($traits[self::TRAIT])) {
            return [];
        }

        self::validateEventStreams($model, $operationShapeId, $operation);

        $outputShapeId = $operation['output']['target'] ?? null;

        return WaiterDefinitionNormalizer::normalize(
            $operationShapeId,
            $traits[self::TRAIT],
            \is_string($outputShapeId)
                && $outputShapeId !== 'smithy.api#Unit'
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private static function validateEventStreams(
        SmithyModel $model,
        string $operationShapeId,
        array $operation
    ): void
    {
        foreach (['input', 'output'] as $direction) {
            $structureId = $operation[$direction]['target'] ?? null;
            if (!\is_string($structureId)) {
                continue;
            }

            foreach ($model->getMembers($structureId) as $member) {
                $target = $member['target'] ?? null;
                if (!\is_string($target)
                    || $model->getShapeType($target) !== 'union'
                    || !$model->hasTrait(
                        $target,
                        'smithy.api#streaming'
                    )
                ) {
                    continue;
                }

                throw new \LogicException(
                    "Operation {$operationShapeId} waitable trait: "
                        . 'waiters cannot target an event-stream operation.'
                );
            }
        }
    }
}
