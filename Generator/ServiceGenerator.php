<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
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
    public function generate(string $shapeId): GeneratedFile
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
        $writer->addImport('Smithy\\Core\\Shape\\GeneratedServiceTrait');
        $writer->addImport('Smithy\\Core\\Shape\\ServiceInterface');

        // Import operation classes
        $operationsNamespace = $this->settings->resolveOperationsNamespace();
        $operationImports = [];
        $operationIds = $this->model->getServiceOperationIds($shapeId);
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
