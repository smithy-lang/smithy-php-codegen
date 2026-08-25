<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generate the service-level exception class.
 *
 * This is a catch-all exception for any error from a specific service.
 * All modeled error shapes for the service extend this class.
 */
final class ServiceExceptionGenerator
{
    /**
     * @internal
     */
    public function __construct(
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate the service exception class.
     *
     * @param string $serviceName Short service name (e.g., "Weather")
     * @return GeneratedFile
     */
    public function generate(string $serviceName): GeneratedFile
    {
        $className = $serviceName . 'Exception';
        $namespace = $this->settings->resolveNamespace(
            \Smithy\Codegen\ShapeCategory::Error
        );
        $outputDir = $this->settings->resolveOutputDir(
            \Smithy\Codegen\ShapeCategory::Error
        );

        // Resolve the parent exception class
        $parentFqcn = $this->settings->baseServiceException
            ?? 'Smithy\\Core\\Exception\\ApiException';
        $parentShortName = $this->extractShortName($parentFqcn);

        $writer = new PhpCodeWriter($namespace);

        // Import the parent exception
        $writer->addImport($parentFqcn);

        $writer->writeDocBlock([
            "Base exception for all {$serviceName} service errors.",
            '',
            "Catch this to handle any error from the {$serviceName} service,",
            'whether modeled or unmodeled.'
        ]);

        $writer->writeLine("class {$className} extends {$parentShortName}");
        $writer->openBlock('{');
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private function extractShortName(string $fqcn): string
    {
        $lastSlash = strrpos($fqcn, '\\');
        if ($lastSlash === false) {
            return $fqcn;
        }
        return substr($fqcn, $lastSlash + 1);
    }
}
