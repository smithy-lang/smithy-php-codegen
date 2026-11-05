<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SensitiveShapeDetector;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates PHP union types from Smithy union shapes.
 *
 * Smithy unions become an abstract readonly class with:
 * - A private constructor (prevents external subclassing)
 * - Static factory methods for each variant
 * - Separate files for each variant class (PSR-4 compliant)
 *
 * Generated file structure:
 *   Payload.php              - abstract readonly class with factory methods
 *   PayloadStringValue.php   - final readonly class extending Payload
 *   PayloadIntValue.php      - final readonly class extending Payload
 */
final class UnionGenerator
{
    private const RESERVED_FACTORY_METHODS = [
        '__construct',
        'create',
        'createByIndex',
        'getMemberIndex',
        'getSchema',
        'jsonSerialize',
        'schema',
        'serialize',
        'tag',
        'unknown',
        'value'
    ];

    private readonly SensitiveShapeDetector $sensitiveShapeDetector;

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings,
        ?SensitiveShapeDetector $sensitiveShapeDetector = null
    )
    {
        $this->sensitiveShapeDetector = $sensitiveShapeDetector
            ?? new SensitiveShapeDetector($model);
    }

    /**
     * Generate PHP code for a union shape.
     *
     * Returns multiple GeneratedFile instances: one for the abstract base class
     * and one for each variant subclass.
     *
     * @param string $shapeId Fully-qualified shape ID
     * @return GeneratedFile[]
     */
    public function generate(string $shapeId): array
    {
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException("Shape not found: {$shapeId}");
        }

        if (($shape['type'] ?? '') !== 'union') {
            throw new \InvalidArgumentException(
                "Shape {$shapeId} is not a union"
            );
        }

        $variantNames = $this->allocateVariantNames($shapeId);
        $files = [];

        // Generate the abstract base class
        $files[] = $this->generateBaseClass($shapeId, $variantNames);

        // Generate each variant as a separate file
        $members = $this->model->getMembers($shapeId);
        $className = $this->symbolProvider->toShortClassName($shapeId);

        $memberIndex = 0;
        foreach ($members as $memberName => $memberDef) {
            $files[] = $this->generateVariantClass(
                $shapeId,
                $memberName,
                $memberDef,
                $className,
                $variantNames[$memberName]['class'],
                $memberIndex
            );
            $memberIndex++;
        }

        // Generate the Unknown variant
        $files[] = $this->generateUnknownVariant($shapeId, $className);

        return $files;
    }

    /** @param array<string, array{factory: string, class: string}> $variantNames */
    private function generateBaseClass(
        string $shapeId,
        array $variantNames
    ): GeneratedFile
    {
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);

        $writer = new PhpCodeWriter($namespace);

        // Add imports
        $writer->addImport('Smithy\\Core\\Shape\\UnionInterface');
        $writer->addImport('Smithy\\Core\\Shape\\Utils\\ArrayShapeSerializer');
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        $withSensitiveStringable =
            $this->sensitiveShapeDetector->containsSensitiveData($shapeId);
        $jsonSerializable = null;
        if ($withSensitiveStringable) {
            foreach ($this->model->getMembers($shapeId) as $memberName => $_) {
                $phpType = $this->symbolProvider->toMemberType(
                    $shapeId,
                    $memberName
                );
                if ($phpType->requiresImport() && $phpType->fqcn !== null) {
                    $writer->addImport($phpType->fqcn);
                }
            }
            $jsonSerializable = $writer->addImportReference(
                'JsonSerializable',
                'PhpJsonSerializable',
                [$className, ...\array_column($variantNames, 'class')]
            );
            $writer->addImport(
                'Smithy\\Core\\Shape\\SensitiveStringableTrait'
            );
        }

        // Write doc comment
        $documentation = $this->model->getTrait(
            $shapeId,
            'smithy.api#documentation'
        );
        if ($documentation !== null) {
            $cleanDoc = $this->cleanDocumentation((string) $documentation);
            $writer->writeDocBlock([$cleanDoc]);
        }

        // Open the abstract class - implements UnionInterface, which extends
        // SerializableShapeInterface for the universal `serialize()` entry
        // point. The variant-dispatching body is concrete (lives on the
        // abstract base) so every variant inherits it. active-variant
        // accessors stay abstract since they vary per variant.
        $interfaces = ['UnionInterface'];
        if ($jsonSerializable !== null) {
            $interfaces[] = $jsonSerializable;
        }
        $writer->writeClassDeclaration(
            "abstract readonly class {$className}",
            $interfaces
        );
        $writer->openBlock('{');

        if ($withSensitiveStringable) {
            $writer->writeLine('use SensitiveStringableTrait;');
            $writer->writeLine();
        }

        // Public shape-ID constant for typed lookups (logging, metrics,
        // type registries) that need the Smithy ID without a schema load.
        // Inherited by every variant subclass.
        $writer->writeLine(
            "public const SHAPE_ID = '" . \addslashes($shapeId) . "';"
        );
        $writer->writeLine();

        // No per-class Schema cache here. The generated union base is an
        // `abstract readonly class`, and PHP 8.2+ propagates `readonly`
        // to every property - including static ones - which the engine
        // then rejects at declare time ("Static property ... cannot be
        // readonly"). The `Schemas::foo()` factory this class proxies to
        // already carries its own isset-guard + memoized static, so a
        // second local cache layer would be pure overhead anyway.

        // Abstract active-variant methods from UnionInterface.
        $writer->writeLine('abstract public function getMemberIndex(): int;');
        $writer->writeLine();
        $writer->writeLine('abstract public function tag(): string;');
        $writer->writeLine();
        $writer->writeLine('abstract public function value(): mixed;');
        $writer->writeLine();

        // Universal `serialize()` entry point - concrete on the base class
        // so every variant inherits the same dispatch into
        // `serializeUnion()`.
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
            '$serializer->serializeUnion(self::schema(), $this, $idx);'
        );
        $writer->closeBlock();
        $writer->writeLine();

        // Static factory methods
        $this->writeFactoryMethods(
            $writer,
            $shapeId,
            $className,
            $variantNames
        );

        // schema() method
        $this->writeSchemaMethod($writer, $shapeId);
        $writer->writeLine();

        // create() method for internal deserialization
        $this->writeCreateMethod(
            $writer,
            $shapeId,
            $className,
            $variantNames
        );
        $this->writeCreateByIndexMethod(
            $writer,
            $shapeId,
            $className,
            $variantNames
        );

        // Close the abstract class
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function generateVariantClass(
        string $shapeId,
        string $memberName,
        array $memberDef,
        string $baseClassName,
        string $variantClassName,
        int $memberIndex
    ): GeneratedFile
    {
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);

        $phpType = $this->symbolProvider->toMemberType($shapeId, $memberName);
        $writer = new PhpCodeWriter($namespace);

        // Add import for the value type if needed
        if ($phpType->requiresImport() && $phpType->fqcn !== null) {
            $writer->addImport($phpType->fqcn);
        }

        // Doc comment
        $traits = $memberDef['traits'] ?? [];
        $memberDoc = $traits['smithy.api#documentation'] ?? null;
        if ($memberDoc !== null) {
            $writer->writeDocBlock([(string) $memberDoc]);
        }

        $writer->writeLine("/** @internal */");
        $writer->writeLine(
            "final readonly class {$variantClassName} extends {$baseClassName}"
        );
        $writer->openBlock('{');
        $writer->writeLine(
            "public function __construct(public {$phpType->typeHint} \$value) {}"
        );
        $writer->writeLine();
        $writer->writeLine('public function getMemberIndex(): int');
        $writer->openBlock('{');
        $writer->writeLine("return {$memberIndex};");
        $writer->closeBlock();
        $writer->writeLine();
        $writer->writeLine('public function tag(): string');
        $writer->openBlock('{');
        $writer->writeLine("return '{$memberName}';");
        $writer->closeBlock();
        $writer->writeLine();
        $writer->writeLine("public function value(): {$phpType->typeHint}");
        $writer->openBlock('{');
        $writer->writeLine('return $this->value;');
        $writer->closeBlock();
        $writer->closeBlock();

        $filePath = $outputDir
            . DIRECTORY_SEPARATOR
            . $variantClassName
            . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function generateUnknownVariant(
        string $shapeId,
        string $baseClassName
    ): GeneratedFile
    {
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);

        $variantClassName = $baseClassName . 'Unknown';

        $writer = new PhpCodeWriter($namespace);

        $writer->writeDocBlock(['Unknown variant for forward-compatibility.']);
        $writer->writeLine("/** @internal */");
        $writer->writeClassDeclaration(
            "final readonly class {$variantClassName} extends {$baseClassName}"
        );
        $writer->openBlock('{');
        $writer->writeLine(
            'public function __construct('
            . 'private string $unknownTag, '
            . 'private mixed $unknownValue'
            . ') {}'
        );
        $writer->writeLine();
        $writer->writeLine('public function getMemberIndex(): int');
        $writer->openBlock('{');
        $writer->writeLine('return -1;');
        $writer->closeBlock();
        $writer->writeLine();
        $writer->writeLine('public function tag(): string');
        $writer->openBlock('{');
        $writer->writeLine('return $this->unknownTag;');
        $writer->closeBlock();
        $writer->writeLine();
        $writer->writeLine('public function value(): mixed');
        $writer->openBlock('{');
        $writer->writeLine('return $this->unknownValue;');
        $writer->closeBlock();
        $writer->closeBlock();

        $filePath = $outputDir
            . DIRECTORY_SEPARATOR
            . $variantClassName
            . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function writeFactoryMethods(
        PhpCodeWriter $writer,
        string $shapeId,
        string $className,
        array $variantNames
    ): void
    {
        $members = $this->model->getMembers($shapeId);

        foreach ($members as $memberName => $memberDef) {
            $phpType = $this->symbolProvider->toMemberType(
                $shapeId,
                $memberName
            );
            $factoryName = $variantNames[$memberName]['factory'];
            $variantClassName = $variantNames[$memberName]['class'];

            // Add import if needed
            if ($phpType->requiresImport() && $phpType->fqcn !== null) {
                $writer->addImport($phpType->fqcn);
            }

            // Write documentation
            $traits = $memberDef['traits'] ?? [];
            $memberDoc = $traits['smithy.api#documentation'] ?? null;
            if ($memberDoc !== null) {
                $writer->writeDocBlock([(string) $memberDoc]);
            }

            $writer->writeLine(
                "public static function {$factoryName}("
                . "{$phpType->typeHint} \$value): self"
            );
            $writer->openBlock('{');
            $writer->writeLine("return new {$variantClassName}(\$value);");
            $writer->closeBlock();
            $writer->writeLine();
        }

        // Unknown factory
        $writer->writeDocBlock(
            ['Create an unknown variant for forward-compatibility.']
        );
        $writer->writeLine(
            "public static function unknown(string \$tag, mixed \$value): self"
        );
        $writer->openBlock('{');
        $writer->writeLine("return new {$className}Unknown(\$tag, \$value);");
        $writer->closeBlock();
        $writer->writeLine();
    }

    /**
     * Emit `public static function schema(): Schema` as a thin proxy onto
     * `Schemas::foo()`. The `Schemas::foo()` factory already carries its
     * own isset-guarded static, so this method just forwards.
     *
     * Unlike the struct proxy in {@see StructureGenerator::writeSchemaMethod},
     * we do NOT declare a per-class `$SCHEMA` static here - the union base
     * is an `abstract readonly class`, and PHP 8.2+ propagates `readonly`
     * to every property including statics, which the engine rejects at
     * declare time ("Static property ... cannot be readonly").
     *
     * @return void
     */
    private function writeSchemaMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $methodName = lcfirst($className);

        // Register the service-level `Schemas` class so the emitted proxy
        // body resolves to the correct FQCN under whatever namespace the
        // codegen settings target. Matches
        // `StructureGenerator::writeSchemaMethod`.
        $schemasNamespace = $this->settings->namespace . '\\Schemas';
        $writer->addImport($schemasNamespace);

        $writer->writeDocBlock(['Get the shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine("return Schemas::{$methodName}();");
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock(['Get the shape schema for this instance.']);
        $writer->writeLine('public function getSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::schema();');
        $writer->closeBlock();
    }


    private function writeCreateMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        string $className,
        array $variantNames
    ): void
    {
        $members = $this->model->getMembers($shapeId);

        $writer->writeLine();
        $writer->writeDocBlock([
            'Create a union variant from a tag and value.',
            '',
            '@param string $tag The variant name',
            '@param mixed $value The variant value',
            '@return self'
        ]);
        $writer->writeLine('public static function create(string $tag, mixed $value): self');
        $writer->openBlock('{');
        $writer->writeLine('return match ($tag) {');
        $writer->pushIndent();

        foreach ($members as $memberName => $memberDef) {
            $writer->writeLine(
                "'{$memberName}' => "
                . $this->buildVariantConstructionExpression(
                    $shapeId,
                    $memberName,
                    $memberDef,
                    $variantNames[$memberName]['class']
                )
                . ','
            );
        }

        $writer->writeLine(
            "default => new {$className}Unknown(\$tag, \$value),"
        );
        $writer->popIndent();
        $writer->writeLine('};');
        $writer->closeBlock();
    }

    private function writeCreateByIndexMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        string $className,
        array $variantNames
    ): void
    {
        $members = $this->model->getMembers($shapeId);

        $writer->writeLine();
        $writer->writeDocBlock([
            'Create a known union variant from its schema member index.',
            '',
            '@param int $index The variant member index',
            '@param mixed $value The variant value',
            '@return self'
        ]);
        $writer->writeLine(
            'public static function createByIndex(int $index, mixed $value): self'
        );
        $writer->openBlock('{');
        $writer->writeLine('return match ($index) {');
        $writer->pushIndent();

        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $writer->writeLine(
                "{$index} => "
                . $this->buildVariantConstructionExpression(
                    $shapeId,
                    $memberName,
                    $memberDef,
                    $variantNames[$memberName]['class']
                )
                . ','
            );
            $index++;
        }

        $writer->writeLine(
            "default => throw new \\InvalidArgumentException("
            . "'Unknown {$className} variant index: ' . \$index),"
        );
        $writer->popIndent();
        $writer->writeLine('};');
        $writer->closeBlock();
    }

    /**
     * Build a direct generated union variant construction expression.
     *
     * @param array<string, mixed> $memberDef
     */
    private function buildVariantConstructionExpression(
        string $shapeId,
        string $memberName,
        array $memberDef,
        string $variantClass
    ): string
    {
        $targetId = $memberDef['target'] ?? null;
        $targetType = $targetId !== null
            ? $this->model->getShapeType($targetId)
            : null;

        if ($targetType === 'structure' || $targetType === 'union') {
            $targetClass = $this->symbolProvider->toShortClassName($targetId);
            $value = "\$value instanceof {$targetClass}"
                . " ? \$value"
                . " : ArrayShapeSerializer::fromArray("
                . "{$targetClass}::class, \$value)";

            return "new {$variantClass}({$value})";
        }

        if ($targetType === 'boolean') {
            return "new {$variantClass}((bool) \$value)";
        }

        if (\in_array(
            $targetType,
            ['integer', 'long', 'short', 'byte'],
            true
        )) {
            return "new {$variantClass}((int) \$value)";
        }

        if (\in_array($targetType, ['float', 'double'], true)) {
            return "new {$variantClass}((float) \$value)";
        }

        if ($targetType === 'enum' || $targetType === 'intEnum') {
            $targetClass = $this->symbolProvider->toShortClassName($targetId);
            $value = "\$value instanceof {$targetClass}"
                . " ? \$value"
                . " : {$targetClass}::fromValue(\$value)";

            return "new {$variantClass}({$value})";
        }

        return "new {$variantClass}(\$value)";
    }

    /** @return array<string, array{factory: string, class: string}> */
    private function allocateVariantNames(string $shapeId): array
    {
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $usedMethodNames = [];
        foreach (self::RESERVED_FACTORY_METHODS as $methodName) {
            $usedMethodNames[\strtolower($methodName)] = true;
        }

        $names = [];
        foreach ($this->model->getMembers($shapeId) as $memberName => $_) {
            $factoryName = $this->symbolProvider->toPropertyName($memberName);
            while (isset($usedMethodNames[\strtolower($factoryName)])) {
                $factoryName .= 'Value';
            }
            $usedMethodNames[\strtolower($factoryName)] = true;
            $names[$memberName] = [
                'factory' => $factoryName,
                'class' => $className . \ucfirst($factoryName)
            ];
        }

        return $names;
    }

    /**
     * Build the `traits: [...]` literal for a union member schema.
     * Currently emits the JSON-codec-relevant traits (`@jsonName`,
     * `@timestampFormat`). Returns null when no relevant traits exist
     * so the schema literal stays compact.
     *
     * @param array<string, mixed> $traits
     */
    private function buildTraitsLiteral(array $traits): ?string
    {
        $entries = [];
        if (isset($traits['smithy.api#jsonName'])) {
            $value = \var_export((string) $traits['smithy.api#jsonName'], true);
            $entries[] = "Schema::T_JSON_NAME => {$value}";
        }
        if (isset($traits['smithy.api#timestampFormat'])) {
            $value = \var_export(
                (string) $traits['smithy.api#timestampFormat'],
                true
            );
            $entries[] = "Schema::T_TIMESTAMP_FORMAT => {$value}";
        }
        if ($entries === []) {
            return null;
        }
        return '[' . \implode(', ', $entries) . ']';
    }

    private function smithyTypeToEnum(string $type): string
    {
        return match ($type) {
            'structure' => 'ShapeType::Structure',
            'union' => 'ShapeType::Union',
            'enum' => 'ShapeType::Enum',
            'intEnum' => 'ShapeType::IntEnum',
            'list' => 'ShapeType::List_',
            'map' => 'ShapeType::Map',
            'string' => 'ShapeType::String_',
            'integer' => 'ShapeType::Integer',
            'long' => 'ShapeType::Long',
            'float' => 'ShapeType::Float_',
            'double' => 'ShapeType::Double',
            'boolean' => 'ShapeType::Boolean',
            'timestamp' => 'ShapeType::Timestamp',
            'blob' => 'ShapeType::Blob',
            default => 'ShapeType::String_'
        };
    }

    private function cleanDocumentation(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
