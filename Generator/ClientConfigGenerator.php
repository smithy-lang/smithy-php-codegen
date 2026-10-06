<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generate a service-specific ClientConfig type.
 */
final class ClientConfigGenerator
{
    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Generate the client config for a service.
     */
    public function generate(string $serviceShapeId): GeneratedFile
    {
        $shape = $this->model->getShape($serviceShapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException(
                "Service not found: {$serviceShapeId}"
            );
        }
        if (($shape['type'] ?? '') !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceShapeId} is not a service"
            );
        }

        $shortName = $this->shortName($serviceShapeId);
        $className = $shortName . 'ClientConfig';
        $configClass = '\\' . \ltrim(
            $this->settings->clientConfigClass,
            '\\'
        );
        $writer = new PhpCodeWriter($this->settings->namespace);
        $writer->writeDocBlock([
            "Configuration for {@see {$shortName}Client}."
        ]);
        $writer->writeLine(
            "final class {$className} extends {$configClass} {}"
        );

        return new GeneratedFile(
            $this->settings->outputDir
                . DIRECTORY_SEPARATOR
                . $className
                . '.php',
            $writer->toFileContent()
        );
    }

    private function shortName(string $serviceShapeId): string
    {
        $parts = \explode('\\', $this->settings->namespace);
        $last = \end($parts);
        if ($last !== false && $last !== '') {
            return $last;
        }

        return SmithyModel::getShapeName($serviceShapeId);
    }
}
