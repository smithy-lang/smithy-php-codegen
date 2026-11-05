<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

use Smithy\Codegen\Generator\GeneratedFile;
use Smithy\Codegen\Model\SmithyModel;

/**
 * Immutable state exposed to code generation integrations.
 */
final readonly class CodegenContext
{
    /**
     * @param list<GeneratedFile> $generatedFiles
     */
    public function __construct(
        public CodegenSettings $settings,
        public SmithyModel $model,
        public PhpSymbolProviderInterface $symbolProvider,
        public array $generatedFiles
    ) {}
}
