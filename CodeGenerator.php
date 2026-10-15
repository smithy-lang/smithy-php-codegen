<?php

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
use Smithy\Codegen\Model\SensitiveShapeDetector;
use Smithy\Codegen\Model\SmithyModel;

/**
 * Main code generation orchestrator.
 *
 * Reads a Smithy model and generates PHP types for all applicable shapes.
 */
final class CodeGenerator
{
    private readonly SmithyModel $model;
    private readonly PhpSymbolProvider $symbolProvider;
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
     * @internal
     */
    public function __construct(
        private readonly CodegenSettings $settings,
        SmithyModel $model
    ) {
        $this->model = $model;
        $this->symbolProvider = new PhpSymbolProvider($model, $settings);

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
            $settings
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
     * Generate PHP types for all shapes in the model.
     *
     * @return GeneratedFile[] List of generated files
     */
    public function generate(): array
    {
        $this->validateServiceCount();

        $generatedFiles = [];

        // Build the service closure - all shapes reachable from service
        // operations
        $serviceClosure = $this->buildServiceClosure();

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
        // The registry maps every generated shape ID to its PHP class so
        // the protocol layer and application code can resolve schemas
        // from wire-side discriminators (e.g. restJson1 `__type`) without
        // per-operation error lists.
        foreach ($this->model->getServiceShapes() as $shapeId => $shape) {
            if (str_starts_with($shapeId, 'smithy.api#')) {
                continue;
            }

            $generatedFiles[] = $this->serviceGenerator->generate($shapeId);
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
                $generatedFiles[] = $this->clientGenerator->generate($shapeId);
            }

            if ($this->settings->generateClientConfig) {
                $generatedFiles[] = $this->clientConfigGenerator->generate(
                    $shapeId
                );
            }
        }


        // Generate service-level exception if configured
        if ($this->settings->serviceExceptionClass !== null) {
            $serviceName = $this->extractShortName(
                $this->settings->serviceExceptionClass
            );
            $serviceName = \preg_replace(
                '/Exception$/',
                '',
                $serviceName
            );
            $generatedFiles[] = $this->serviceExceptionGenerator->generate(
                $serviceName
            );
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
        if ($this->hasUnitReference()) {
            $generatedFiles[] = $this->generateUnit();
        }

        $generatedFiles[] = $this->schemasGenerator->generate(
            $serviceClosure
        );

        return $generatedFiles;
    }

    /**
     * Build the set of shape IDs reachable from the service (its closure).
     * Only shapes in this set are generated.
     *
     * @return array<string, true> Set of shape IDs to generate
     */
    private function buildServiceClosure(): array
    {
        $closure = [];
        $queue = [];

        // Start with every operation in each service closure.
        $hasService = false;
        foreach ($this->model->getServiceShapes() as $serviceId => $_) {
            $hasService = true;
            foreach ($this->model->getServiceOperationIds($serviceId)
                as $operationId
            ) {
                $queue[] = $operationId;
            }
        }

        // No service shape in the model - the codegen test fixtures (and
        // service-less standalone-shape models) seed the closure with every
        // declared non-prelude shape so the generators see the whole graph.
        // The walk below still prunes mixin/prelude refs as usual.
        if (!$hasService) {
            foreach ($this->model->getShapes() as $shapeId => $_) {
                if (!str_starts_with($shapeId, 'smithy.api#')) {
                    $queue[] = $shapeId;
                }
            }
        }

        // Walk the graph breadth-first
        while ($queue !== []) {
            $shapeId = array_pop($queue);

            if (isset($closure[$shapeId])
                || str_starts_with($shapeId, 'smithy.api#')
            ) {
                continue;
            }

            $closure[$shapeId] = true;

            $shape = $this->model->getShape($shapeId);
            if ($shape === null) {
                continue;
            }

            // Enqueue referenced shapes based on type
            $type = $shape['type'] ?? '';

            // Operation -> input, output, errors
            if ($type === 'operation') {
                if (isset($shape['input']['target'])) {
                    $queue[] = $shape['input']['target'];
                }
                if (isset($shape['output']['target'])) {
                    $queue[] = $shape['output']['target'];
                }
                foreach ($shape['errors'] ?? [] as $err) {
                    if (isset($err['target'])) {
                        $queue[] = $err['target'];
                    }
                }
            }

            // Structure/union -> member targets
            if ($type === 'structure' || $type === 'union') {
                foreach ($shape['members'] ?? [] as $memberDef) {
                    if (isset($memberDef['target'])) {
                        $queue[] = $memberDef['target'];
                    }
                    // Also follow mixin targets
                }
                // Follow mixins
                foreach ($shape['mixins'] ?? [] as $mixin) {
                    if (isset($mixin['target'])) {
                        $queue[] = $mixin['target'];
                    }
                }
            }

            // List -> member target
            if ($type === 'list') {
                if (isset($shape['member']['target'])) {
                    $queue[] = $shape['member']['target'];
                }
            }

            // Map -> key + value targets
            if ($type === 'map') {
                if (isset($shape['key']['target'])) {
                    $queue[] = $shape['key']['target'];
                }
                if (isset($shape['value']['target'])) {
                    $queue[] = $shape['value']['target'];
                }
            }
        }

        return $closure;
    }

    /**
     * Determine if a shape should be generated.
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

    private function extractShortName(string $fqcn): string
    {
        $lastSlash = strrpos($fqcn, '\\');
        if ($lastSlash === false) {
            return $fqcn;
        }
        return substr($fqcn, $lastSlash + 1);
    }

    /**
     * Check if any operation references smithy.api#Unit as input or output.
     */
    private function hasUnitReference(): bool
    {
        foreach ($this->model->getOperationShapes() as $shape) {
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

    private function validateServiceCount(): void
    {
        $serviceIds = [];
        foreach ($this->model->getServiceShapes() as $shapeId => $_) {
            if (!\str_starts_with($shapeId, 'smithy.api#')) {
                $serviceIds[] = $shapeId;
            }
        }
        if (\count($serviceIds) <= 1) {
            return;
        }

        throw new \InvalidArgumentException(
            'Code generation requires at most one service shape; found: '
                . \implode(', ', $serviceIds)
        );
    }

    /**
     * Generate the Unit class (empty input/output structure for smithy.api#Unit).
     */
    private function generateUnit(): GeneratedFile
    {
        $namespace = $this->settings->resolveNamespace(ShapeCategory::Shape);
        $outputDir = $this->settings->resolveOutputDir(ShapeCategory::Shape);

        $writer = new Writer\PhpCodeWriter($namespace);
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Serde\\SerializableStructInterface');
        $writer->addImport('Smithy\\Core\\Serde\\ShapeSerializerInterface');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        $writer->addImport('Smithy\\Core\\Shape\\FromMembersTrait');

        $writer->writeDocBlock(
            ['The Unit type represents an empty input/output shape.']
        );
        $writer->writeClassDeclaration(
            'final class Unit',
            ['SerializableStructInterface']
        );
        $writer->openBlock('{');

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
            path: $outputDir . DIRECTORY_SEPARATOR . 'Unit.php',
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
