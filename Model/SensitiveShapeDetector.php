<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Model;

/**
 * Finds shapes that directly or transitively contain sensitive data.
 *
 * The detector builds reverse shape dependencies once, then propagates
 * sensitivity from directly annotated shapes and members to every containing
 * structure, union, list, map, or mixin consumer.
 *
 * @internal
 */
final class SensitiveShapeDetector
{
    private const SENSITIVE_TRAIT = 'smithy.api#sensitive';

    /** @var array<string, true> */
    private array $sensitiveShapes = [];

    public function __construct(SmithyModel $model)
    {
        /** @var array<string, array<string, true>> $dependents */
        $dependents = [];

        foreach ($model->getShapes() as $shapeId => $shape) {
            if (\array_key_exists(
                self::SENSITIVE_TRAIT,
                $shape['traits'] ?? []
            )) {
                $this->sensitiveShapes[$shapeId] = true;
            }

            foreach ($shape['mixins'] ?? [] as $mixin) {
                $mixinId = $mixin['target'] ?? null;
                if (!\is_string($mixinId)
                    || $this->isMixinLocalSensitive($model, $mixinId)
                ) {
                    continue;
                }

                $this->addDependency(
                    $dependents,
                    $mixinId,
                    $shapeId
                );
            }

            $type = $shape['type'] ?? null;
            if ($type === 'structure' || $type === 'union') {
                foreach ($model->getMembers($shapeId) as $member) {
                    $this->indexMember(
                        $dependents,
                        $shapeId,
                        $member
                    );
                }
            } elseif ($type === 'list') {
                $this->indexMember(
                    $dependents,
                    $shapeId,
                    $shape['member'] ?? []
                );
            } elseif ($type === 'map') {
                $this->indexMember(
                    $dependents,
                    $shapeId,
                    $shape['key'] ?? []
                );
                $this->indexMember(
                    $dependents,
                    $shapeId,
                    $shape['value'] ?? []
                );
            }
        }

        $queue = \array_keys($this->sensitiveShapes);
        for ($index = 0; isset($queue[$index]); $index++) {
            foreach ($dependents[$queue[$index]] ?? [] as $shapeId => $_) {
                if (isset($this->sensitiveShapes[$shapeId])) {
                    continue;
                }

                $this->sensitiveShapes[$shapeId] = true;
                $queue[] = $shapeId;
            }
        }
    }

    public function containsSensitiveData(string $shapeId): bool
    {
        return isset($this->sensitiveShapes[$shapeId]);
    }

    /**
     * @param array<string, array<string, true>> $dependents
     * @param array<string, mixed> $member
     *
     * @return void
     */
    private function indexMember(
        array &$dependents,
        string $containerId,
        array $member
    ): void
    {
        if (\array_key_exists(
            self::SENSITIVE_TRAIT,
            $member['traits'] ?? []
        )) {
            $this->sensitiveShapes[$containerId] = true;
        }

        $this->addDependency(
            $dependents,
            $member['target'] ?? null,
            $containerId
        );
    }

    /**
     * @param array<string, array<string, true>> $dependents
     *
     * @return void
     */
    private function addDependency(
        array &$dependents,
        ?string $targetId,
        string $containerId
    ): void
    {
        if ($targetId !== null) {
            $dependents[$targetId][$containerId] = true;
        }
    }

    private function isMixinLocalSensitive(
        SmithyModel $model,
        string $mixinId
    ): bool
    {
        $mixinTrait = $model->getTrait($mixinId, 'smithy.api#mixin');
        $localTraits = \is_array($mixinTrait)
            ? $mixinTrait['localTraits'] ?? []
            : [];

        return \is_array($localTraits)
            && \in_array(self::SENSITIVE_TRAIT, $localTraits, true);
    }
}
