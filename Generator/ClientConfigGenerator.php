<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

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
    private const ABSTRACT_CLIENT_CONFIG =
        'Smithy\\Client\\AbstractClientConfig';

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
        $writer = new PhpCodeWriter($this->settings->namespace);
        $configClass = $writer->addImportReference(
            $this->settings->clientConfigClass,
            'BaseClientConfig',
            [$className]
        );
        $writer->writeDocBlock([
            "Configuration for {@see {$shortName}Client}."
        ]);
        if (\ltrim($this->settings->clientConfigClass, '\\')
            !== self::ABSTRACT_CLIENT_CONFIG
        ) {
            $writer->writeLine(
                "final class {$className} extends {$configClass} {}"
            );
        } else {
            $writer->writeLine(
                "final class {$className} extends {$configClass}"
            );
            $writer->openBlock('{');
            $this->writeCopyWith($writer);
            $writer->closeBlock();
        }

        return new GeneratedFile(
            $this->settings->outputDir
                . DIRECTORY_SEPARATOR
                . $className
                . '.php',
            $writer->toFileContent()
        );
    }

    /**
     * Write the copy method that rebuilds the config from base fields.
     */
    private function writeCopyWith(PhpCodeWriter $writer): void
    {
        $writer->writeDocBlock([
            'Shallow copy the config, overriding fields named in `$overrides`.',
            '',
            'Explicit `null` values keep the current value.',
            '',
            '@param array<string, mixed> $overrides'
        ]);
        $writer->writeLine(
            'protected function copyWith(array $overrides): static'
        );
        $writer->openBlock('{');
        foreach ([
            '$protocol = \\array_key_exists(\'protocol\', $overrides)',
            '    ? $overrides[\'protocol\']',
            '    : $this->protocol;',
            '$protocolId = \\array_key_exists(\'protocolId\', $overrides)',
            '    ? $overrides[\'protocolId\']',
            '    : $this->protocolId;',
            '',
            'return new static(',
            '    transport: $overrides[\'transport\'] ?? $this->transport,',
            '    endpointResolver: $overrides[\'endpointResolver\']',
            '        ?? $this->endpointResolver,',
            '    protocol: $protocol,',
            '    protocolResolver: $overrides[\'protocolResolver\']',
            '        ?? $this->protocolResolver,',
            '    protocolId: $protocolId,',
            '    interceptors: $overrides[\'interceptors\']',
            '        ?? $this->interceptorChain,',
            '    supportedAuthSchemes: $overrides[\'supportedAuthSchemes\']',
            '        ?? $this->supportedAuthSchemes,',
            '    authSchemeResolver: $overrides[\'authSchemeResolver\']',
            '        ?? $this->authSchemeResolver,',
            '    retryStrategy: $overrides[\'retryStrategy\']',
            '        ?? $this->retryStrategy,',
            '    retryScope: $overrides[\'retryScope\'] ?? $this->retryScope,',
            '    context: $overrides[\'context\'] ?? $this->context,',
            '    authSchemePreference: $overrides[\'authSchemePreference\']',
            '        ?? $this->authSchemePreference,',
            '    service: $overrides[\'service\'] ?? $this->service,',
            '    pluginState: $overrides[\'pluginState\']',
            '        ?? $this->getPluginState(),',
            '    explicitFields: $this->explicitFieldsFor($overrides),',
            '    identityResolvers: $overrides[\'identityResolvers\']',
            '        ?? $this->identityResolvers',
            ');'
        ] as $line) {
            $writer->writeLine($line);
        }
        $writer->closeBlock();
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
