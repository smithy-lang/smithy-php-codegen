<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Integration;

/**
 * Instantiate explicitly configured, Composer-autoloadable integrations.
 *
 * @internal
 */
final class CodegenIntegrationLoader
{
    /**
     * @param mixed $definitions
     *
     * @return list<CodegenIntegrationInterface>
     */
    public function load(mixed $definitions): array
    {
        if (!\is_array($definitions) || !\array_is_list($definitions)) {
            throw new \InvalidArgumentException(
                'integrations must be a list'
            );
        }

        $integrations = [];
        foreach ($definitions as $index => $definition) {
            if (!\is_array($definition) || \array_is_list($definition)) {
                throw new \InvalidArgumentException(
                    "integrations[{$index}] must be an object"
                );
            }
            $unknown = \array_diff(
                \array_keys($definition),
                ['class', 'settings']
            );
            if ($unknown !== []) {
                throw new \InvalidArgumentException(
                    "Unknown integrations[{$index}] setting: "
                        . \reset($unknown)
                );
            }

            $class = $definition['class'] ?? null;
            if (!\is_string($class) || $class === '') {
                throw new \InvalidArgumentException(
                    "integrations[{$index}].class must be a non-empty string"
                );
            }
            if (!\class_exists($class)) {
                throw new \InvalidArgumentException(
                    "Codegen integration class does not exist: {$class}"
                );
            }

            try {
                $integration = new $class();
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException(
                    "Could not instantiate codegen integration {$class}",
                    0,
                    $error
                );
            }
            if (!$integration instanceof CodegenIntegrationInterface) {
                throw new \InvalidArgumentException(
                    "Codegen integration {$class} must implement "
                        . CodegenIntegrationInterface::class
                );
            }

            $settings = $definition['settings'] ?? [];
            if (!\is_array($settings)
                || (\array_is_list($settings) && $settings !== [])
            ) {
                throw new \InvalidArgumentException(
                    "integrations[{$index}].settings must be an object"
                );
            }
            try {
                $integration->configure($settings);
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException(
                    "Could not configure codegen integration {$class}: "
                        . $error->getMessage(),
                    0,
                    $error
                );
            }
            $integrations[] = $integration;
        }

        return $integrations;
    }
}
