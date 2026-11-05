<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Integration;

/**
 * Deterministically order codegen integrations.
 *
 * @internal
 */
final class CodegenIntegrationSorter
{
    /**
     * @param iterable<CodegenIntegrationInterface> $integrations
     *
     * @return list<CodegenIntegrationInterface>
     */
    public static function sort(iterable $integrations): array
    {
        $byKey = [];
        $names = [];
        $positions = [];
        $position = 0;
        foreach ($integrations as $integration) {
            if (!$integration instanceof CodegenIntegrationInterface) {
                throw new \InvalidArgumentException(
                    'Codegen integrations must implement '
                        . CodegenIntegrationInterface::class
                );
            }

            $name = $integration->name();
            if ($name === '') {
                throw new \InvalidArgumentException(
                    'Codegen integration names must not be empty'
                );
            }
            $key = self::key($name);
            if (isset($byKey[$key])) {
                throw new \InvalidArgumentException(
                    "Duplicate codegen integration name: {$name}"
                );
            }

            $byKey[$key] = $integration;
            $names[$key] = $name;
            $positions[$key] = $position;
            $position++;
        }

        $edges = \array_fill_keys(\array_keys($byKey), []);
        $indegrees = \array_fill_keys(\array_keys($byKey), 0);
        foreach ($byKey as $key => $integration) {
            foreach ($integration->runBefore() as $target) {
                $targetKey = self::key($target);
                if (isset($byKey[$targetKey])) {
                    self::addEdge($key, $targetKey, $edges, $indegrees);
                }
            }
            foreach ($integration->runAfter() as $target) {
                $targetKey = self::key($target);
                if (isset($byKey[$targetKey])) {
                    self::addEdge($targetKey, $key, $edges, $indegrees);
                }
            }
        }

        $ordered = [];
        while (\count($ordered) < \count($byKey)) {
            $available = [];
            foreach ($indegrees as $key => $indegree) {
                if ($indegree === 0 && !isset($ordered[$key])) {
                    $available[] = $key;
                }
            }
            if ($available === []) {
                $remaining = [];
                foreach ($indegrees as $key => $indegree) {
                    if ($indegree > 0) {
                        $remaining[] = $names[$key];
                    }
                }
                \sort($remaining, SORT_STRING);
                throw new \InvalidArgumentException(
                    'Codegen integration ordering contains a cycle: '
                        . \implode(', ', $remaining)
                );
            }

            \usort(
                $available,
                static function (string $left, string $right) use (
                    $byKey,
                    $positions
                ): int {
                    $priority = $byKey[$right]->priority()
                        <=> $byKey[$left]->priority();

                    return $priority !== 0
                        ? $priority
                        : $positions[$left] <=> $positions[$right];
                }
            );
            $key = $available[0];
            $ordered[$key] = $byKey[$key];
            foreach ($edges[$key] as $target => $_) {
                $indegrees[$target]--;
            }
        }

        return \array_values($ordered);
    }

    private static function key(string $name): string
    {
        return 'integration:' . $name;
    }

    /**
     * @param array<string, array<string, true>> $edges
     * @param array<string, int> $indegrees
     */
    private static function addEdge(
        string $source,
        string $target,
        array &$edges,
        array &$indegrees
    ): void {
        if (isset($edges[$source][$target])) {
            return;
        }

        $edges[$source][$target] = true;
        $indegrees[$target]++;
    }
}
