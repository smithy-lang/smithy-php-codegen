<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

use Smithy\Codegen\Generator\ClientConfigGenerator;
use Smithy\Codegen\Generator\ClientGenerator;
use Smithy\Codegen\Generator\EndpointBytecodeGenerator;
use Smithy\Codegen\Generator\EnumGenerator;
use Smithy\Codegen\Generator\GeneratedFile;
use Smithy\Codegen\Generator\GeneratedOutputPublisher;
use Smithy\Codegen\Generator\OperationGenerator;
use Smithy\Codegen\Generator\SchemasGenerator;
use Smithy\Codegen\Generator\ServiceExceptionGenerator;
use Smithy\Codegen\Generator\ServiceGenerator;
use Smithy\Codegen\Generator\TypeRegistryGenerator;
use Smithy\Codegen\Generator\StructureGenerator;
use Smithy\Codegen\Generator\UnionGenerator;
use Smithy\Codegen\Integration\CodegenIntegrationInterface;
use Smithy\Codegen\Integration\CodegenIntegrationSorter;
use Smithy\Codegen\Model\SensitiveShapeDetector;
use Smithy\Codegen\Model\SmithyModel;

/**
 * Main code generation orchestrator.
 *
 * Reads a Smithy model and generates PHP types for all applicable shapes.
 */
final class CodeGenerator
{
    private readonly CodegenSettings $settings;
    private readonly SmithyModel $model;
    private readonly PhpSymbolProviderInterface $symbolProvider;
    /** @var list<CodegenIntegrationInterface> */
    private readonly array $integrations;
    private readonly StructureGenerator $structureGenerator;
    private readonly EnumGenerator $enumGenerator;
    private readonly UnionGenerator $unionGenerator;
    private readonly OperationGenerator $operationGenerator;
    private readonly ServiceGenerator $serviceGenerator;
    private readonly ServiceExceptionGenerator $serviceExceptionGenerator;
    private readonly SchemasGenerator $schemasGenerator;
    private readonly TypeRegistryGenerator $typeRegistryGenerator;
    private readonly EndpointBytecodeGenerator $endpointBytecodeGenerator;
    private readonly ClientGenerator $clientGenerator;
    private readonly ClientConfigGenerator $clientConfigGenerator;

    /**
     * @param iterable<CodegenIntegrationInterface> $integrations
     */
    public function __construct(
        CodegenSettings $settings,
        SmithyModel $model,
        iterable $integrations = []
    ) {
        $this->integrations = CodegenIntegrationSorter::sort($integrations);
        foreach ($this->integrations as $integration) {
            $model = $integration->preprocessModel($model, $settings);
        }
        foreach ($this->integrations as $integration) {
            $settings = $integration->customizeSettings($settings, $model);
        }

        $symbolProvider = new PhpSymbolProvider($model, $settings);
        foreach ($this->integrations as $integration) {
            $symbolProvider = $integration->decorateSymbolProvider(
                $symbolProvider,
                $model,
                $settings
            );
        }
        $this->validateGeneratedClassSymbols(
            $symbolProvider,
            $model,
            $settings
        );

        $this->settings = $settings;
        $this->model = $model;
        $this->symbolProvider = $symbolProvider;

        // Inspect the previously-generated `Schemas.php` at the output
        // directory to recover each shape's historical member ordering.
        // On first run the file doesn't exist and this returns null,
        // yielding fresh ordering - same behavior as before this
        // feature landed. On regen, the evaluator's map is threaded
        // through the generators that emit or consume member-indexed output
        // (`StructureGenerator` for the class files,
        // `SchemasGenerator` for the schema registry, and
        // `OperationGenerator` for stream and HTTP path bindings). The check is
        // gated by `CodegenSettings::$preserveExistingMemberOrder`
        // so callers can opt out for scratch generations or a
        // deliberate ABI-break release.
        //
        // A hard failure during inspection (unreadable file, malformed
        // token stream, unbalanced parens) propagates up - never
        // silently degrade to fresh ordering because that would break
        // ABI without warning.
        $priorCodegen = $settings->preserveExistingMemberOrder
            ? PriorCodegenEvaluator::inspect($settings->outputDir)
            : null;
        $sensitiveShapeDetector = new SensitiveShapeDetector($model);

        $this->structureGenerator = new StructureGenerator(
            $model,
            $this->symbolProvider,
            $settings,
            $priorCodegen,
            $sensitiveShapeDetector
        );
        $this->enumGenerator = new EnumGenerator(
            $model,
            $this->symbolProvider,
            $settings
        );
        $this->unionGenerator = new UnionGenerator(
            $model,
            $this->symbolProvider,
            $settings,
            $sensitiveShapeDetector
        );
        $this->operationGenerator = new OperationGenerator(
            $model,
            $this->symbolProvider,
            $settings,
            $priorCodegen
        );
        $this->serviceGenerator = new ServiceGenerator($model, $settings);
        $this->serviceExceptionGenerator = new ServiceExceptionGenerator(
            $settings,
            $this->integrations
        );
        $this->schemasGenerator = new SchemasGenerator(
            $model,
            $this->symbolProvider,
            $settings,
            $priorCodegen
        );
        $this->typeRegistryGenerator = new TypeRegistryGenerator(
            $model,
            $this->symbolProvider,
            $settings
        );
        $this->endpointBytecodeGenerator = new EndpointBytecodeGenerator(
            $model,
            $settings
        );
        $this->clientGenerator = new ClientGenerator(
            $model,
            $this->symbolProvider,
            $settings
        );
        $this->clientConfigGenerator = new ClientConfigGenerator(
            $model,
            $settings
        );
    }

    /**
     * Get the effective settings after integration customization.
     */
    public function getSettings(): CodegenSettings
    {
        return $this->settings;
    }

    /**
     * Generate PHP types for all shapes in the model.
     *
     * @return list<GeneratedFile> List of generated files
     */
    public function generate(): array
    {
        $projection = ServiceProjection::fromModel(
            $this->model,
            $this->settings->operationSet
        );

        return $this->generateProjection($projection);
    }

    /**
     * @return list<GeneratedFile>
     */
    private function generateProjection(ServiceProjection $projection): array
    {
        $generatedFiles = [];
        $serviceException = null;
        $serviceClosure = $projection->shapeIds;
        $this->validateSchemaAccessorSymbols($serviceClosure);

        // Generate structures
        foreach ($this->model->getStructureShapes() as $shapeId => $shape) {
            if (!$this->shouldGenerateShape(
                $shapeId,
                $shape,
                $serviceClosure
            )) {
                continue;
            }

            $generatedFiles[] = $this->structureGenerator->generate(
                $shapeId
            );
        }

        // Generate enums
        foreach ($this->model->getEnumShapes() as $shapeId => $shape) {
            if (!$this->shouldGenerateShape(
                $shapeId,
                $shape,
                $serviceClosure
            )) {
                continue;
            }

            $generatedFiles[] = $this->enumGenerator->generate($shapeId);
        }

        // Generate unions (each union produces multiple files)
        foreach ($this->model->getUnionShapes() as $shapeId => $shape) {
            if (!$this->shouldGenerateShape(
                $shapeId,
                $shape,
                $serviceClosure
            )) {
                continue;
            }

            $unionFiles = $this->unionGenerator->generate($shapeId);
            \array_push($generatedFiles, ...$unionFiles);
        }

        // Generate service class(es) and their matching TypeRegistry.
        // The registry maps every generated shape ID to its PHP class for
        // application code. No runtime code reads it.
        foreach ($this->model->getServiceShapes() as $shapeId => $shape) {
            if (str_starts_with($shapeId, 'smithy.api#')) {
                continue;
            }

            $generatedFiles[] = $this->serviceGenerator->generate(
                $shapeId,
                $projection
            );
            $serviceException =
                $this->serviceExceptionGenerator->generate();
            $generatedFiles[] = $serviceException;
            $generatedFiles[] = $this->typeRegistryGenerator->generate(
                $shapeId,
                $serviceClosure
            );

            // Emit the compiled endpoint bytecode class (and, when
            // `CodegenSettings::$generateEndpointKeyBuilder` is on, the
            // sibling `<Service>EndpointKeyBuilder`) if this service
            // carries a `smithy.rules#endpointBdd` trait. When it
            // doesn't, `generateAll()` returns `[]` and we skip.
            foreach ($this->endpointBytecodeGenerator->generateAll(
                $shapeId
            ) as $file) {
                $generatedFiles[] = $file;
            }

            if ($this->settings->generateClient) {
                $generatedFiles[] =
                    $this->clientGenerator->generateInterface(
                        $shapeId,
                        $projection
                    );
                $generatedFiles[] = $this->clientGenerator->generate(
                    $shapeId,
                    $projection
                );
            }

            if ($this->settings->generateClientConfig) {
                $generatedFiles[] = $this->clientConfigGenerator->generate(
                    $shapeId
                );
            }
        }

        if ($serviceException === null
            && $this->settings->serviceExceptionClass !== null
        ) {
            $serviceException = $this->serviceExceptionGenerator->generate();
            $generatedFiles[] = $serviceException;
        }

        // Generate operations
        foreach ($this->model->getOperationShapes() as $shapeId => $shape) {
            if (!isset($serviceClosure[$shapeId])) {
                continue;
            }

            $generatedFiles[] = $this->operationGenerator->generate(
                $shapeId
            );
        }

        // Generate Unit class if any operation references smithy.api#Unit
        if ($projection->usesUnit) {
            $generatedFiles[] = $this->generateUnit();
        }

        $generatedFiles[] = $this->schemasGenerator->generate(
            $serviceClosure
        );

        $this->validateServiceExceptionCollision(
            $generatedFiles,
            $serviceException
        );
        foreach ($this->integrations as $integration) {
            $context = new CodegenContext(
                settings: $this->settings,
                model: $this->model,
                symbolProvider: $this->symbolProvider,
                generatedFiles: $generatedFiles
            );
            foreach ($integration->generateAdditionalFiles($context)
                as $file
            ) {
                if (!$file instanceof GeneratedFile) {
                    throw new \UnexpectedValueException(
                        "Codegen integration {$integration->name()} must "
                            . 'generate instances of '
                            . GeneratedFile::class
                    );
                }
                $generatedFiles[] = $file;
            }
        }
        $this->assertUniquePaths($generatedFiles);

        return $generatedFiles;
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function validateServiceExceptionCollision(
        array $files,
        ?GeneratedFile $serviceException
    ): void
    {
        if ($serviceException === null) {
            return;
        }

        $path = self::outputPathKey($serviceException->path);
        $matches = 0;
        foreach ($files as $file) {
            if (self::outputPathKey($file->path) === $path
                && ++$matches > 1
            ) {
                throw new \InvalidArgumentException(
                    'Generated service exception '
                        . $this->settings->resolveServiceExceptionClass()
                        . ' collides with '
                        . 'another generated artifact; configure '
                        . 'serviceExceptionClass with a unique name'
                );
            }
        }
    }

    private static function outputPathKey(string $path): string
    {
        return \strtolower($path);
    }

    /**
     * Determine if a shape should be generated.
     *
     * @param array<string, mixed> $shape
     * @param array<string, true> $serviceClosure
     */
    private function shouldGenerateShape(
        string $shapeId,
        array $shape,
        array $serviceClosure
    ): bool
    {
        // Skip mixins
        $traits = $shape['traits'] ?? [];
        if (isset($traits['smithy.api#mixin'])) {
            return false;
        }

        // Skip shapes not in the service closure
        if (!isset($serviceClosure[$shapeId])) {
            return false;
        }

        return true;
    }

    private function validateGeneratedClassSymbols(
        PhpSymbolProviderInterface $symbolProvider,
        SmithyModel $model,
        CodegenSettings $settings
    ): void
    {
        $shapeSets = [
            $model->getStructureShapes(),
            $model->getEnumShapes(),
            $model->getUnionShapes()
        ];
        foreach ($shapeSets as $shapes) {
            foreach ($shapes as $shapeId => $shape) {
                if (\str_starts_with($shapeId, 'smithy.api#')
                    || isset(($shape['traits'] ?? [])['smithy.api#mixin'])
                ) {
                    continue;
                }

                $this->validateGeneratedClassSymbol(
                    $symbolProvider,
                    $shapeId,
                    $settings->resolveNamespace(
                        $model->categorizeShape($shapeId)
                    )
                );
            }
        }

        if (self::modelHasUnitReference($model)) {
            $this->validateGeneratedClassSymbol(
                $symbolProvider,
                'smithy.api#Unit',
                $settings->resolveNamespace(ShapeCategory::Shape)
            );
        }
    }

    private static function modelHasUnitReference(SmithyModel $model): bool
    {
        foreach ($model->getOperationShapes() as $shape) {
            $inputTarget = $shape['input']['target'] ?? null;
            $outputTarget = $shape['output']['target'] ?? null;
            if ($inputTarget === null
                || $outputTarget === null
                || $inputTarget === 'smithy.api#Unit'
                || $outputTarget === 'smithy.api#Unit'
            ) {
                return true;
            }
        }

        return false;
    }

    private function validateGeneratedClassSymbol(
        PhpSymbolProviderInterface $symbolProvider,
        string $shapeId,
        string $expectedNamespace
    ): void
    {
        $shortName = $symbolProvider->toShortClassName($shapeId);
        $expectedClass = $expectedNamespace . '\\' . $shortName;
        $actualClass = \ltrim(
            $symbolProvider->toClassName($shapeId),
            '\\'
        );
        if (\str_contains($shortName, '\\')
            || $actualClass !== $expectedClass
        ) {
            throw new \LogicException(
                "Codegen integration moved {$shapeId} outside "
                    . "the configured {$expectedNamespace} namespace: "
                    . $actualClass
            );
        }
    }

    /**
     * @param array<string, true> $serviceClosure
     */
    private function validateSchemaAccessorSymbols(
        array $serviceClosure
    ): void
    {
        $accessors = [];
        $shapeSets = [
            $this->model->getStructureShapes(),
            $this->model->getEnumShapes(),
            $this->model->getUnionShapes()
        ];
        foreach ($shapeSets as $shapes) {
            foreach ($shapes as $shapeId => $shape) {
                if (!isset($serviceClosure[$shapeId])
                    || isset(($shape['traits'] ?? [])['smithy.api#mixin'])
                ) {
                    continue;
                }

                $shortName = $this->symbolProvider->toShortClassName(
                    $shapeId
                );
                $key = \strtolower($shortName);
                $previousShapeId = $accessors[$key] ?? null;
                if ($previousShapeId !== null
                    && $previousShapeId !== $shapeId
                ) {
                    throw new \LogicException(
                        "Codegen integrations assigned schema accessor "
                            . "{$shortName} to both {$previousShapeId} and "
                            . "{$shapeId}"
                    );
                }
                $accessors[$key] = $shapeId;
            }
        }
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function assertUniquePaths(array $files): void
    {
        $paths = [];
        foreach ($files as $file) {
            $displayPath = \str_replace('\\', '/', $file->path);
            $path = $file->pathRelativeTo($this->settings->outputDir);
            $key = DIRECTORY_SEPARATOR === '\\'
                ? \strtolower($path)
                : $path;
            if (isset($paths[$key])) {
                throw new \LogicException(
                    "Multiple generators emitted {$displayPath}"
                );
            }
            $paths[$key] = true;
        }
    }

    /**
     * Generate the Unit class (empty input/output structure for smithy.api#Unit).
     */
    private function generateUnit(): GeneratedFile
    {
        $category = ShapeCategory::Shape;
        $namespace = $this->settings->resolveNamespace($category);
        $outputDir = $this->settings->resolveOutputDir($category);
        $className = $this->symbolProvider->toShortClassName(
            'smithy.api#Unit'
        );

        $writer = new Writer\PhpCodeWriter($namespace);
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Serde\\SerializableStructInterface');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        $writer->addImport('Smithy\\Core\\Shape\\FromMembersTrait');

        $withArrayAccess = $this->settings->shouldGenerateArrayAccess(
            $category
        );
        $readonlyArrayAccessTrait = $withArrayAccess
            ? $writer->addImportReference(
                'Smithy\\Core\\Shape\\ReadonlyArrayAccessTrait',
                'SmithyReadonlyArrayAccessTrait',
                [$className]
            )
            : null;
        $arraySerializableTrait =
            $this->settings->shouldGenerateArraySerialization($category)
                ? $writer->addImportReference(
                    'Smithy\\Core\\Shape\\ArraySerializableTrait',
                    'SmithyArraySerializableTrait',
                    [$className]
                )
                : null;

        $interfaces = ['SerializableStructInterface'];
        if ($withArrayAccess) {
            $interfaces[] = '\\ArrayAccess';
        }

        $writer->writeDocBlock(
            ['The Unit type represents an empty input/output shape.']
        );
        $writer->writeClassDeclaration(
            "final class {$className}",
            $interfaces
        );
        $writer->openBlock('{');

        if ($readonlyArrayAccessTrait !== null) {
            $writer->writeLine("use {$readonlyArrayAccessTrait};");
        }
        if ($arraySerializableTrait !== null) {
            $writer->writeLine("use {$arraySerializableTrait};");
        }

        // fromMembers() comes from FromMembersTrait, shared with all
        // generated struct shapes. Unit inherits the same deserialization
        // sink even though its member list is empty.
        $writer->writeLine('use FromMembersTrait;');
        $writer->writeLine();

        $writer->writeLine('/** @var Schema|null */');

        $writer->writeLine('private static ?Schema $__schema = null;');
        $writer->writeLine();
        $writer->writeLine('public function __construct() {}');
        $writer->writeLine();

        $writer->writeDocBlock(['Get the shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine("return self::\$__schema ??= new Schema(");
        $writer->pushIndent();
        $writer->writeLine("id: 'smithy.api#Unit',");
        $writer->writeLine('type: ShapeType::Structure,');
        $writer->writeLine('members: [],');
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock(['Get the shape schema for this instance.']);
        $writer->writeLine('public function getSchema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::schema();');
        $writer->closeBlock();
        $writer->writeLine();

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
            '$serializer->serializeStruct(self::schema(), $this, $idx);'
        );
        $writer->closeBlock();
        $writer->writeLine();

        $writer->writeDocBlock([
            'Serialize the shape members to the desired wire format.',
            '',
            '@return void'
        ]);
        $writer->writeLine(
            'public function serializeMembers('
            . 'ShapeSerializerInterface $serializer): void {}'
        );
        $writer->writeLine();

        $writer->writeDocBlock(['Get a modeled member value.']);
        $writer->writeLine('public function getMemberValue(Schema $member): mixed');
        $writer->openBlock('{');
        $writer->writeLine('return null;');
        $writer->closeBlock();

        $writer->closeBlock();

        return new GeneratedFile(
            path: $outputDir . DIRECTORY_SEPARATOR . $className . '.php',
            content: $writer->toFileContent()
        );
    }

    /**
     * Generate all shapes and transactionally replace the output directory.
     *
     * The configured output directory is managed generated output. Files not
     * produced by the current generation are removed during publication. The
     * output tree must not be modified concurrently.
     *
     * @return int Number of files written
     */
    public function generateAndWrite(): int
    {
        $files = $this->generate();
        (new GeneratedOutputPublisher())->publish(
            $files,
            $this->settings->outputDir
        );

        return count($files);
    }
}
