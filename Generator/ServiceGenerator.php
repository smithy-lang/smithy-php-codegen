<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\ServiceProjection;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates a service metadata class from a Smithy service shape.
 *
 * Produces a `final class <ServiceName>Service implements ServiceInterface`
 * with the service name, shape ID, version, and list of operations.
 */
final class ServiceGenerator
{
    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate the service class.
     *
     * @param string $shapeId Fully-qualified service shape ID
     * @return GeneratedFile
     */
    public function generate(
        string $shapeId,
        ServiceProjection $projection
    ): GeneratedFile
    {
        $shape = $this->model->getShape($shapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException("Shape not found: {$shapeId}");
        }

        if (($shape['type'] ?? '') !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$shapeId} is not a service"
            );
        }

        $serviceName = SmithyModel::getShapeName($shapeId);
        $shortName = $this->resolveServiceName($serviceName);
        $className = $shortName . 'Service';

        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        $writer = new PhpCodeWriter($namespace);

        // Add imports
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Shape\\GeneratedServiceTrait');
        $writer->addImport('Smithy\\Core\\Shape\\ServiceInterface');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');

        // Import operation classes
        $operationsNamespace = $this->settings->resolveOperationsNamespace();
        $operationImports = [];
        $projection->requireService($shapeId);
        $operationIds = $projection->operationIds;
        foreach ($operationIds as $operationId) {
            $opName = SmithyModel::getShapeName($operationId);
            $opClassName = $opName . 'Operation';
            $opFqcn = $operationsNamespace . '\\' . $opClassName;
            $writer->addImport($opFqcn);
            $operationImports[] = $opClassName;
        }

        // Write doc comment
        $traits = $shape['traits'] ?? [];
        $documentation = $traits['smithy.api#documentation'] ?? null;
        if ($documentation !== null) {
            $cleanDoc = $this->cleanDocumentation((string) $documentation);
            $writer->writeDocBlock([$cleanDoc]);
        }

        // Get version
        $version = $shape['version'] ?? '';

        // Generate class
        $writer->writeClassDeclaration(
            "final class {$className}",
            ['ServiceInterface']
        );
        $writer->openBlock('{');

        $writer->writeLine('use GeneratedServiceTrait;');
        $writer->writeLine();

        $writer->writeLine('private static ?Schema $SCHEMA = null;');
        $writer->writeLine();

        // name()
        $writer->writeLine('public static function name(): string');
        $writer->openBlock('{');
        $writer->writeLine(
            'return ' . PhpCodeWriter::exportLiteral($shortName) . ';'
        );
        $writer->closeBlock();
        $writer->writeLine();

        // id()
        $writer->writeLine('public static function id(): string');
        $writer->openBlock('{');
        $writer->writeLine(
            'return ' . PhpCodeWriter::exportLiteral($shapeId) . ';'
        );
        $writer->closeBlock();
        $writer->writeLine();

        // version()
        $writer->writeLine('public static function version(): string');
        $writer->openBlock('{');
        $writer->writeLine(
            'return ' . PhpCodeWriter::exportLiteral($version) . ';'
        );
        $writer->closeBlock();
        $writer->writeLine();

        $this->writeSchemaMethod($writer, $shapeId, $traits);
        $writer->writeLine();

        // operations()
        $writer->writeLine('public static function operations(): array');
        $writer->openBlock('{');
        if (empty($operationImports)) {
            $writer->writeLine('return [];');
        } else {
            $writer->writeLine('return [');
            $writer->pushIndent();
            foreach ($operationImports as $opClass) {
                $writer->writeLine("{$opClass}::class,");
            }
            $writer->popIndent();
            $writer->writeLine('];');
        }
        $writer->closeBlock();

        // Close class
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    /**
     * @param array<string, mixed> $traits
     */
    private function writeSchemaMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        array $traits
    ): void
    {
        $configuredTraits = [];
        foreach ($this->settings->schemaTraitIndices as $traitId => $index) {
            if (\array_key_exists($traitId, $traits)) {
                $configuredTraits[$traitId] = [
                    'index' => $index,
                    'value' => $traits[$traitId]
                ];
            }
        }

        $writer->writeDocBlock(['Get the service shape schema.']);
        $writer->writeLine('public static function schema(): Schema');
        $writer->openBlock('{');
        $writer->writeLine('return self::$SCHEMA ??= new Schema(');
        $writer->pushIndent();
        $writer->writeLine(
            'id: ' . PhpCodeWriter::exportLiteral($shapeId) . ','
        );
        $writer->writeLine('type: ShapeType::Service,');
        if ($configuredTraits !== []) {
            $writer->writeLine('traits: [');
            $writer->pushIndent();
            foreach ($configuredTraits as $trait) {
                $writer->writeLine(
                    $trait['index']
                        . ' => '
                        . self::exportTraitLiteral($trait['value'])
                        . ','
                );
            }
            $writer->popIndent();
            $writer->writeLine('],');
            $writer->writeLine('traitsById: [');
            $writer->pushIndent();
            foreach ($configuredTraits as $traitId => $trait) {
                $writer->writeLine(
                    PhpCodeWriter::exportLiteral($traitId)
                        . ' => '
                        . self::exportTraitLiteral($trait['value'])
                        . ','
                );
            }
            $writer->popIndent();
            $writer->writeLine('],');
        }
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    private static function exportTraitLiteral(mixed $value): string
    {
        if ($value === [] || $value === (object)[]) {
            return 'true';
        }

        return PhpCodeWriter::exportLiteral($value);
    }

    private function resolveServiceName(string $name): string
    {
        $parts = \explode('\\', $this->settings->namespace);
        $lastPart = \end($parts);
        if ($lastPart !== false && $lastPart !== '') {
            return $lastPart;
        }

        return $name;
    }

    private function cleanDocumentation(string $text): string
    {
        $text = \strip_tags($text);
        $text = \preg_replace('/\s+/', ' ', $text) ?? '';

        return \trim($text);
    }
}
