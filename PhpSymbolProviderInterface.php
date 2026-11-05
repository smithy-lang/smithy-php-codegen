<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

/**
 * Resolve PHP symbols for modeled Smithy shapes.
 */
interface PhpSymbolProviderInterface
{
    /**
     * Resolve PHP type information for a Smithy shape.
     */
    public function toPhpType(string $shapeId): PhpType;

    /**
     * Resolve PHP type information for a structure or union member.
     */
    public function toMemberType(
        string $parentShapeId,
        string $memberName
    ): PhpType;

    /**
     * Convert a Smithy member name to a PHP property name.
     */
    public function toPropertyName(string $memberName): string;

    /**
     * Resolve the fully qualified PHP class name for a Smithy shape.
     */
    public function toClassName(string $shapeId): string;

    /**
     * Resolve the unqualified PHP class name for a Smithy shape.
     */
    public function toShortClassName(string $shapeId): string;
}
