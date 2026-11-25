<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Integration\CodegenIntegrationInterface;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generate the service-level exception class.
 *
 * This is a catch-all exception for any error from a specific service.
 * All modeled error shapes for the service extend this class. Integrations
 * add class members through
 * {@see CodegenIntegrationInterface::serviceExceptionMembers()}.
 */
final class ServiceExceptionGenerator
{
    /**
     * @param iterable<CodegenIntegrationInterface> $integrations
     *
     * @internal
     */
    public function __construct(
        private readonly CodegenSettings $settings,
        private readonly iterable $integrations = []
    ) {}

    /**
     * Generate the service exception class.
     */
    public function generate(): GeneratedFile
    {
        $serviceName = $this->settings->resolveServiceName();
        $serviceExceptionClass =
            $this->settings->resolveServiceExceptionClass();
        $className = self::shortClassName($serviceExceptionClass);
        $namespace = $this->settings->resolveNamespace(
            \Smithy\Codegen\ShapeCategory::Error
        );
        $outputDir = $this->settings->resolveOutputDir(
            \Smithy\Codegen\ShapeCategory::Error
        );

        $parentFqcn = $this->settings->baseServiceException
            ?? 'Smithy\\Core\\Exception\\ApiException';
        if (\strcasecmp(
            \ltrim($parentFqcn, '\\'),
            \ltrim($serviceExceptionClass, '\\')
        ) === 0) {
            throw new \InvalidArgumentException(
                'baseServiceException must not be the generated service '
                    . 'exception class'
            );
        }
        $writer = new PhpCodeWriter($namespace);
        $parentClass = $writer->addImportReference(
            $parentFqcn,
            'BaseServiceException',
            [$className]
        );

        $writer->writeDocBlock([
            "Base exception for all {$serviceName} service errors.",
            '',
            "Catch this to handle any error from the {$serviceName} service,",
            'whether modeled or unmodeled.'
        ]);

        $declaration = "class {$className} extends {$parentClass}";
        $memberWriters = [];
        foreach ($this->integrations as $integration) {
            foreach ($integration->serviceExceptionMembers($this->settings)
                as $memberWriter
            ) {
                $memberWriters[] = $memberWriter;
            }
        }
        if ($memberWriters !== []) {
            $writer->writeLine($declaration);
            $writer->openBlock('{');
            foreach ($memberWriters as $index => $memberWriter) {
                if ($index > 0) {
                    $writer->writeLine();
                }
                $memberWriter($writer, $className);
            }
            $writer->closeBlock();
        } elseif (\strlen($declaration . ' {}')
            <= PhpCodeWriter::MAX_GENERATED_LINE_WIDTH
        ) {
            $writer->writeLine($declaration . ' {}');
        } else {
            $writer->writeLine($declaration);
            $writer->writeLine('{}');
        }

        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    private static function shortClassName(string $class): string
    {
        $separator = \strrpos($class, '\\');

        return $separator === false
            ? $class
            : \substr($class, $separator + 1);
    }
}
