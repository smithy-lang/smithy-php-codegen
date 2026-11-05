<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Integration;

use Smithy\Codegen\CodegenContext;
use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Generator\GeneratedFile;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;

/**
 * Customize Smithy PHP code generation.
 */
interface CodegenIntegrationInterface
{
    /**
     * Return the unique name used for configuration and ordering.
     */
    public function name(): string;

    /**
     * Return the ordering priority. Higher priorities run first.
     */
    public function priority(): int;

    /**
     * @return list<string> Integration names that this integration precedes.
     */
    public function runBefore(): array;

    /**
     * @return list<string> Integration names that this integration follows.
     */
    public function runAfter(): array;

    /**
     * Configure an explicitly loaded integration.
     *
     * @param array<string, mixed> $settings
     */
    public function configure(array $settings): void;

    /**
     * Return the model used by subsequent integrations and generators.
     */
    public function preprocessModel(
        SmithyModel $model,
        CodegenSettings $settings
    ): SmithyModel;

    /**
     * Return the settings used by subsequent integrations and generators.
     */
    public function customizeSettings(
        CodegenSettings $settings,
        SmithyModel $model
    ): CodegenSettings;

    /**
     * Decorate the symbol provider used by every built-in generator.
     *
     * Generated classes must remain in the namespaces selected by codegen
     * settings. Integrations may customize type mappings, short class names,
     * and property names.
     */
    public function decorateSymbolProvider(
        PhpSymbolProviderInterface $symbolProvider,
        SmithyModel $model,
        CodegenSettings $settings
    ): PhpSymbolProviderInterface;

    /**
     * Generate artifacts after built-in code generation completes.
     *
     * @return iterable<GeneratedFile>
     */
    public function generateAdditionalFiles(CodegenContext $context): iterable;
}
