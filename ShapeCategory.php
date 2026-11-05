<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

/**
 * Categories for organizing generated shapes into sub-namespaces.
 */
enum ShapeCategory
{
    /** Operation input structures */
    case Input;

    /** Operation output structures */
    case Output;

    /** Error structures (shapes with the @error trait) */
    case Error;

    /** All other shapes (structures, enums, unions used as members) */
    case Shape;
}
