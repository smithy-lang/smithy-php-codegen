<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates PHP enum classes from Smithy enum and intEnum shapes.
 *
 * Generates a final class with:
 * - Cached singleton instances for known values (zero allocation on repeat access)
 * - Named static factory methods per known value (IDE-friendly)
 * - A `fromValue()` factory that accepts any value (forward-compatible with unknown values)
 * - A `cases()` method listing all known values
 *
 * This provides forward-compatibility: unknown enum values from newer
 * service versions won't crash the client.
 *
 * - Smithy `enum` -> PHP class with string $value
 * - Smithy `intEnum` -> PHP class with int $value
 */
final class EnumGenerator
{
    private const RESERVED_FACTORY_METHODS = [
        '__construct',
        '__toString',
        'cases',
        'fromValue',
        'getSchema',
        'getValue',
        'schema',
        'serialize'
    ];

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate PHP enum class code for an enum or intEnum shape.
     *
     * @param string $shapeId Fully-qualified shape ID
     * @return GeneratedFile
     */
    public function generate(string $shapeId): GeneratedFile
    {
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException("Shape not found: {$shapeId}");
        }

        $shapeType = $shape['type'] ?? '';
        if (!in_array($shapeType, ['enum', 'intEnum'], true)) {
            throw new \InvalidArgumentException(
                "Shape {$shapeId} is not an enum or intEnum "
                . "(got: {$shapeType})"
            );
        }

        $className = $this->symbolProvider->toShortClassName($shapeId);
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);

        $writer = new PhpCodeWriter($namespace);
        $writer->addImport('Smithy\\Core\\Shape\\EnumInterface');
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport($this->settings->namespace . '\\Schemas');

        $backingType = $shapeType === 'intEnum' ? 'int' : 'string';
        $members = $this->model->getMembers($shapeId);

        $usedMethodNames = [];
        foreach (self::RESERVED_FACTORY_METHODS as $methodName) {
            $usedMethodNames[\strtolower($methodName)] = true;
        }

        // Collect member info with collision-safe factory names.
        $enumValues = [];
        foreach ($members as $memberName => $memberDef) {
            $traits = $memberDef['traits'] ?? [];
            $enumValue = $traits['smithy.api#enumValue'] ?? null;

            $methodName = $this->allocateMethodName(
                $this->toConstName($memberName),
                $usedMethodNames
            );
            $enumValues[] = [
                'methodName' => $methodName,
                'value' => $shapeType === 'intEnum'
                    ? (int) $enumValue
                    : (string) ($enumValue ?? $memberName),
                'traits' => $traits
            ];
        }

        // Write doc comment
        $this->writeEnumDoc($writer, $shapeId);

        // Open the class
        $writer->writeClassDeclaration(
            "final class {$className}",
            ['EnumInterface']
        );
        $writer->openBlock('{');

        // Public shape-ID constant for typed lookups (logging, metrics,
        // type registries) that need the Smithy ID without a schema load.
        $writer->writeLine(
            "public const SHAPE_ID = '" . \addslashes($shapeId) . "';"
        );
        $writer->writeLine();

        // Private static cache for known singletons
        $writer->writeLine('private static array $known = [];');
        $writer->writeLine();

        // Public readonly value property
        $writer->writeLine("public readonly {$backingType} \$value;");
        $writer->writeLine();

        // Private constructor
        $writer->writeLine("private function __construct({$backingType} \$value)");
        $writer->openBlock('{');
        $writer->writeLine('$this->value = $value;');
        $writer->closeBlock();
        $writer->writeLine();

        // Named static factory methods for each known value
        foreach ($enumValues as $enumValue) {
            $methodName = $enumValue['methodName'];
            $value = $enumValue['value'];
            $traits = $enumValue['traits'];

            // Doc comment for this factory
            $docLines = [];
            $memberDoc = $traits['smithy.api#documentation'] ?? null;
            if ($memberDoc !== null) {
                $docLines[] = $this->cleanDocumentation((string) $memberDoc);
            }
            if (isset($traits['smithy.api#deprecated'])) {
                if (!empty($docLines)) {
                    $docLines[] = '';
                }
                $docLines[] = '@deprecated';
            }
            if (!empty($docLines)) {
                $writer->writeDocBlock($docLines);
            }

            $escapedValue = $this->renderValue($value, $backingType);
            $writer->writeLine("public static function {$methodName}(): self");
            $writer->openBlock('{');
            $writer->writeLine(
                "return self::\$known[{$escapedValue}] ??= "
                . "new self({$escapedValue});"
            );
            $writer->closeBlock();
            $writer->writeLine();
        }

        // fromValue() - accepts any value, caches known values
        $writer->writeDocBlock([
            "Create an instance from a {$backingType} value.",
            '',
            'Known values are cached as singletons.',
            'Unknown values create a fresh instance',
            "for forward-compatibility with newer service versions.",
            '',
            "@param {$backingType} \$value",
            '@return self'
        ]);
        $writer->writeLine("public static function fromValue({$backingType} \$value): self");
        $writer->openBlock('{');
        $writer->writeLine('return match ($value) {');
        $writer->pushIndent();
        foreach ($enumValues as $enumValue) {
            $value = $enumValue['value'];
            $escapedValue = $this->renderValue($value, $backingType);
            $writer->writeLine(
                "{$escapedValue} => self::\$known[{$escapedValue}] "
                . "??= new self({$escapedValue}),"
            );
        }
        $writer->writeLine('default => new self($value),');
        $writer->popIndent();
        $writer->writeLine('};');
        $writer->closeBlock();
        $writer->writeLine();

        // cases() - returns all known values
        $writer->writeDocBlock([
            'Returns all known enum values.',
            '',
            '@return list<self>'
        ]);
        $writer->writeLine('public static function cases(): array');
        $writer->openBlock('{');
        $casesList = implode(
            ', ',
            \array_map(
                static fn(array $enumValue): string =>
                    "self::{$enumValue['methodName']}()",
                $enumValues
            )
        );
        $writer->writeLine("return [{$casesList}];");
        $writer->closeBlock();
        $writer->writeLine();

        // EnumInterface::getValue()
        $writer->writeLine("public function getValue(): {$backingType}");
        $writer->openBlock('{');
        $writer->writeLine('return $this->value;');
        $writer->closeBlock();
        $writer->writeLine();

        // EnumInterface::schema() - returns the Schema cached by the
        // centralized Schemas class. Used by the universal serialize()
        // entry point below and any consumer that needs the enum's own
        // schema (validators, type-stamping codecs).
        $schemaMethod = lcfirst($className);
        $writer->writeDocBlock(['Get the shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine("return Schemas::{$schemaMethod}();");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock(['Get the shape schema for this instance.']);
        $writer->writeLine('public function getSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::schema();');
        $writer->closeBlock();
        $writer->writeLine();

        // SerializableShapeInterface::serialize() - dispatches into the
        // codec's typed scalar method with the enum's own schema. When the
        // enum is reached as a parent struct member, the parent passes the
        // member schema to serializeString()/serializeInteger() directly,
        // bypassing this method.
        $serializeMethod = $shapeType === 'intEnum'
            ? 'serializeInteger'
            : 'serializeString';
        $writer->writeDocBlock([
            'Serialize the shape to the desired wire format.',
            '',
            '@return void'
        ]);
        $writer->writeLine(
            'public function serialize(ShapeSerializerInterface $serializer, '
            . 'int $idx): void'
        );
        $writer->openBlock('{');
        $writer->writeLine(
            "\$serializer->{$serializeMethod}("
            . 'self::schema(), $this->value, $idx);'
        );
        $writer->closeBlock();
        $writer->writeLine();

        // __toString for string enums
        if ($backingType === 'string') {
            $writer->writeLine('public function __toString(): string');
            $writer->openBlock('{');
            $writer->writeLine('return $this->value;');
            $writer->closeBlock();
        }

        // Close the class
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function writeEnumDoc(PhpCodeWriter $writer, string $shapeId): void
    {
        $documentation = $this->model->getTrait(
            $shapeId,
            'smithy.api#documentation'
        );
        if ($documentation !== null) {
            $writer->writeDocBlock([(string) $documentation]);
        }
    }

    /**
     * Render a value as a PHP literal.
     */
    private function renderValue(string|int $value, string $backingType): string
    {
        if ($backingType === 'int') {
            return (string) $value;
        }

        return PhpCodeWriter::exportLiteral((string) $value);
    }

    /**
     * Convert member name to a valid PHP method name (camelCase).
     */
    private function toConstName(string $name): string
    {
        // If name is already UPPER_SNAKE, convert to camelCase for method name
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            // UPPER_SNAKE -> camelCase: "CRC32" -> "crc32", "HEART" -> "heart"
            $lower = strtolower($name);
            if (str_contains($lower, '_')) {
                return lcfirst(
                    str_replace(' ', '', ucwords(str_replace('_', ' ', $lower)))
                );
            }
            return $lower;
        }
        // PascalCase -> camelCase
        return lcfirst($name);
    }

    /** @param array<string, true> $usedMethodNames */
    private function allocateMethodName(
        string $candidate,
        array &$usedMethodNames
    ): string
    {
        $methodName = $candidate;
        while (isset($usedMethodNames[\strtolower($methodName)])) {
            $methodName .= 'Value';
        }
        $usedMethodNames[\strtolower($methodName)] = true;

        return $methodName;
    }

    private function cleanDocumentation(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
