<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Integration;

use Smithy\Codegen\CodegenContext;
use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;

/**
 * Supply no-op defaults for optional codegen integration phases.
 */
abstract class AbstractCodegenIntegration implements CodegenIntegrationInterface
{
    /** @inheritDoc */
    public function name(): string
    {
        return static::class;
    }

    /** @inheritDoc */
    public function priority(): int
    {
        return 0;
    }

    /** @inheritDoc */
    public function runBefore(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function runAfter(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function configure(array $settings): void {}

    /** @inheritDoc */
    public function preprocessModel(
        SmithyModel $model,
        CodegenSettings $settings
    ): SmithyModel
    {
        return $model;
    }

    /** @inheritDoc */
    public function customizeSettings(
        CodegenSettings $settings,
        SmithyModel $model
    ): CodegenSettings
    {
        return $settings;
    }

    /** @inheritDoc */
    public function decorateSymbolProvider(
        PhpSymbolProviderInterface $symbolProvider,
        SmithyModel $model,
        CodegenSettings $settings
    ): PhpSymbolProviderInterface
    {
        return $symbolProvider;
    }

    /** @inheritDoc */
    public function generateAdditionalFiles(CodegenContext $context): iterable
    {
        return [];
    }
}
