<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Model;

/**
 * Validate the identifier-bearing structure of a Smithy JSON AST.
 *
 * Smithy semantic validation remains the responsibility of the Smithy model
 * assembler. This validator enforces the lexical and container invariants the
 * PHP generator relies on when identifiers are used in PHP symbols and output
 * paths.
 */
final class SmithyModelValidator
{
    /**
     * Matches a Smithy identifier used for shape and member names.
     */
    private const IDENTIFIER_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/D';

    /**
     * Matches an absolute Smithy shape ID in `namespace#shape` form.
     */
    private const SHAPE_ID_PATTERN =
        '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*'
        . '#[A-Za-z_][A-Za-z0-9_]*\z/D';

    private const MEMBER_SHAPE_TYPES = [
        'structure',
        'union',
        'enum',
        'intEnum'
    ];

    private const RESOURCE_OPERATION_BINDINGS = [
        'create',
        'put',
        'read',
        'update',
        'delete',
        'list'
    ];

    private function __construct() {}

    /**
     * Validate a decoded Smithy JSON AST.
     *
     * @param array<string, mixed> $model
     *
     * @return void
     */
    public static function validate(array $model): void
    {
        $shapes = $model['shapes'] ?? [];
        if (!\is_array($shapes)) {
            throw new \RuntimeException(
                'Invalid Smithy model: "shapes" must be an object'
            );
        }

        foreach ($shapes as $shapeId => $shape) {
            if (!\is_string($shapeId)) {
                throw new \RuntimeException(
                    'Invalid Smithy model: shape IDs must be strings'
                );
            }
            self::requireShapeId($shapeId, 'shape ID');
            if (!\is_array($shape)) {
                throw new \RuntimeException(
                    "Invalid Smithy model: shape {$shapeId} must be an object"
                );
            }

            self::validateShape($shapeId, $shape);
        }
    }

    /**
     * @param array<string, mixed> $shape
     *
     * @return void
     */
    private static function validateShape(string $shapeId, array $shape): void
    {
        self::validateTraits($shape['traits'] ?? [], "shape {$shapeId}");
        self::validateReferences(
            $shape['mixins'] ?? [],
            "mixins of {$shapeId}"
        );

        $type = $shape['type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new \RuntimeException(
                "Invalid Smithy model: shape {$shapeId} is missing a type"
            );
        }

        if (\array_key_exists('members', $shape)
            && !\in_array($type, self::MEMBER_SHAPE_TYPES, true)
        ) {
            throw new \RuntimeException(
                "Invalid Smithy model: shape {$shapeId} of type {$type} "
                    . 'must not define members'
            );
        }

        switch ($type) {
            case 'structure':
                // fall through
            case 'union':
                self::validateMembers($shapeId, $shape['members'] ?? []);
                break;

            case 'enum':
                // fall through
            case 'intEnum':
                self::validateEnumMembers($shapeId, $shape['members'] ?? []);
                break;

            case 'list':
                self::validateMemberDefinition(
                    $shape['member'] ?? null,
                    "member of {$shapeId}"
                );
                break;

            case 'map':
                self::validateMemberDefinition(
                    $shape['key'] ?? null,
                    "key of {$shapeId}"
                );
                self::validateMemberDefinition(
                    $shape['value'] ?? null,
                    "value of {$shapeId}"
                );
                break;

            case 'operation':
                self::validateOptionalReference(
                    $shape['input'] ?? null,
                    "input of {$shapeId}"
                );
                self::validateOptionalReference(
                    $shape['output'] ?? null,
                    "output of {$shapeId}"
                );
                self::validateReferences(
                    $shape['errors'] ?? [],
                    "errors of {$shapeId}"
                );
                break;

            case 'service':
                self::validateReferences(
                    $shape['operations'] ?? [],
                    "operations of {$shapeId}"
                );
                self::validateReferences(
                    $shape['resources'] ?? [],
                    "resources of {$shapeId}"
                );
                self::validateReferences(
                    $shape['errors'] ?? [],
                    "errors of {$shapeId}"
                );
                self::validateRenames($shapeId, $shape['rename'] ?? []);
                break;

            case 'resource':
                self::validateResource($shapeId, $shape);
                break;
        }
    }

    /**
     * @return void
     */
    private static function validateMembers(
        string $shapeId,
        mixed $members
    ): void
    {
        if (!\is_array($members)) {
            throw new \RuntimeException(
                "Invalid Smithy model: members of {$shapeId} must be an object"
            );
        }

        foreach ($members as $memberName => $member) {
            if (!\is_string($memberName)
                || \preg_match(self::IDENTIFIER_PATTERN, $memberName) !== 1
            ) {
                throw new \RuntimeException(
                    "Invalid Smithy model: invalid member name in {$shapeId}"
                );
            }
            self::validateMemberDefinition(
                $member,
                "member {$shapeId}\${$memberName}"
            );
        }
    }

    /**
     * @return void
     */
    private static function validateEnumMembers(
        string $shapeId,
        mixed $members
    ): void
    {
        if (!\is_array($members)) {
            throw new \RuntimeException(
                "Invalid Smithy model: members of {$shapeId} must be an object"
            );
        }

        foreach ($members as $memberName => $member) {
            if (!\is_string($memberName)
                || \preg_match(self::IDENTIFIER_PATTERN, $memberName) !== 1
            ) {
                throw new \RuntimeException(
                    "Invalid Smithy model: invalid enum member name in "
                        . $shapeId
                );
            }

            if (!\is_array($member)) {
                throw new \RuntimeException(
                    "Invalid Smithy model: enum member {$shapeId}"
                        . "\${$memberName} must be an object"
                );
            }

            if (\array_key_exists('target', $member)) {
                self::validateRequiredReference(
                    $member,
                    "enum member {$shapeId}\${$memberName}"
                );
            }

            self::validateTraits(
                $member['traits'] ?? [],
                "enum member {$shapeId}\${$memberName}"
            );
        }
    }

    /**
     * @return void
     */
    private static function validateMemberDefinition(
        mixed $member,
        string $context
    ): void
    {
        if (!\is_array($member)) {
            throw new \RuntimeException(
                "Invalid Smithy model: {$context} must be an object"
            );
        }

        self::validateRequiredReference($member, $context);
        self::validateTraits($member['traits'] ?? [], $context);
    }

    /**
     * @param array<string, mixed> $shape
     *
     * @return void
     */
    private static function validateResource(
        string $shapeId,
        array $shape
    ): void
    {
        $identifiers = $shape['identifiers'] ?? [];
        if (!\is_array($identifiers)) {
            throw new \RuntimeException(
                "Invalid Smithy model: identifiers of {$shapeId} "
                    . 'must be an object'
            );
        }
        foreach ($identifiers as $name => $reference) {
            if (!\is_string($name)
                || \preg_match(self::IDENTIFIER_PATTERN, $name) !== 1
            ) {
                throw new \RuntimeException(
                    "Invalid Smithy model: invalid resource identifier in "
                        . $shapeId
                );
            }
            self::validateRequiredReference(
                $reference,
                "identifier {$shapeId}\${$name}"
            );
        }

        foreach (self::RESOURCE_OPERATION_BINDINGS as $binding) {
            self::validateOptionalReference(
                $shape[$binding] ?? null,
                "{$binding} operation of {$shapeId}"
            );
        }

        self::validateMembers($shapeId, $shape['properties'] ?? []);

        $referenceLists = [
            'operations',
            'collectionOperations',
            'resources'
        ];
        foreach ($referenceLists as $field) {
            self::validateReferences(
                $shape[$field] ?? [],
                "{$field} of {$shapeId}"
            );
        }
    }

    /**
     * @return void
     */
    private static function validateReferences(
        mixed $references,
        string $context
    ): void
    {
        if (!\is_array($references) || !\array_is_list($references)) {
            throw new \RuntimeException(
                "Invalid Smithy model: {$context} must be a list"
            );
        }

        foreach ($references as $reference) {
            self::validateRequiredReference($reference, $context);
        }
    }

    /**
     * @return void
     */
    private static function validateOptionalReference(
        mixed $reference,
        string $context
    ): void
    {
        if ($reference === null) {
            return;
        }

        self::validateRequiredReference($reference, $context);
    }

    /**
     * @return void
     */
    private static function validateRequiredReference(
        mixed $reference,
        string $context
    ): void
    {
        if (!\is_array($reference)
            || !isset($reference['target'])
            || !\is_string($reference['target'])
        ) {
            throw new \RuntimeException(
                "Invalid Smithy model: {$context} must contain a target"
            );
        }

        self::requireShapeId($reference['target'], "target in {$context}");
    }

    /**
     * @return void
     */
    private static function validateTraits(mixed $traits, string $context): void
    {
        if (!\is_array($traits)) {
            throw new \RuntimeException(
                "Invalid Smithy model: traits on {$context} must be an object"
            );
        }

        foreach (\array_keys($traits) as $traitId) {
            if (!\is_string($traitId)) {
                throw new \RuntimeException(
                    "Invalid Smithy model: trait IDs on {$context} "
                        . 'must be strings'
                );
            }
            self::requireShapeId($traitId, "trait ID on {$context}");
        }
    }

    /**
     * @return void
     */
    private static function validateRenames(
        string $shapeId,
        mixed $renames
    ): void
    {
        if (!\is_array($renames)) {
            throw new \RuntimeException(
                "Invalid Smithy model: renames of {$shapeId} must be an object"
            );
        }

        foreach ($renames as $target => $name) {
            if (!\is_string($target)) {
                throw new \RuntimeException(
                    "Invalid Smithy model: rename targets of {$shapeId} "
                        . 'must be strings'
                );
            }
            self::requireShapeId($target, "rename target of {$shapeId}");
            if (!\is_string($name)
                || \preg_match(self::IDENTIFIER_PATTERN, $name) !== 1
            ) {
                throw new \RuntimeException(
                    "Invalid Smithy model: invalid rename in {$shapeId}"
                );
            }
        }
    }

    /**
     * @return void
     */
    private static function requireShapeId(
        string $shapeId,
        string $context
    ): void
    {
        if (\preg_match(self::SHAPE_ID_PATTERN, $shapeId) !== 1) {
            throw new \RuntimeException(
                "Invalid Smithy model: invalid {$context} \"{$shapeId}\""
            );
        }
    }
}
