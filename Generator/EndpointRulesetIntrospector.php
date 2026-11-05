<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\Model\SmithyModel;

/**
 * Read endpoint parameters from a service ruleset.
 *
 * @internal
 */
final class EndpointRulesetIntrospector
{
    private const ENDPOINT_BDD_TRAIT = 'smithy.rules#endpointBdd';
    private const ENDPOINT_RULESET_TRAIT = 'smithy.rules#endpointRuleSet';

    /** @var array<string, true> */
    private readonly array $excludedParameters;

    /**
     * @internal
     *
     * @param list<string> $excludedParameters
     */
    public function __construct(
        private readonly SmithyModel $model,
        array $excludedParameters = []
    ) {
        $this->excludedParameters = \array_fill_keys(
            $excludedParameters,
            true
        );
    }

    /**
     * Every parameter declared on the service ruleset, in the order the
     * ruleset lists them. Includes both base-owned and service-specific
     * parameters.
     *
     * @return list<EndpointRulesetParameter>
     */
    public function parameters(string $serviceShapeId): array
    {
        $shape = $this->model->getShape($serviceShapeId);
        if ($shape === null) {
            return [];
        }
        $traits = $shape['traits'] ?? [];
        $ruleset = $traits[self::ENDPOINT_BDD_TRAIT]
            ?? $traits[self::ENDPOINT_RULESET_TRAIT]
            ?? null;
        if (!\is_array($ruleset)) {
            return [];
        }
        $parameters = $ruleset['parameters'] ?? [];
        if (!\is_array($parameters)) {
            return [];
        }

        $out = [];
        foreach ($parameters as $pascalName => $definition) {
            if (!\is_array($definition)) {
                continue;
            }
            $out[] = new EndpointRulesetParameter(
                pascalName: (string) $pascalName,
                phpPropertyName: self::pascalToCamel((string) $pascalName),
                phpType: self::mapType($definition['type'] ?? 'string'),
                default: $definition['default'] ?? null,
                required: (bool) ($definition['required'] ?? false),
                builtIn: isset($definition['builtIn'])
                    ? (string) $definition['builtIn']
                    : null,
                documentation: isset($definition['documentation'])
                    ? (string) $definition['documentation']
                    : null
            );
        }
        return $out;
    }

    /**
     * Return parameters not excluded by the caller.
     *
     * @return list<EndpointRulesetParameter>
     */
    public function extraParameters(string $serviceShapeId): array
    {
        $out = [];
        foreach ($this->parameters($serviceShapeId) as $param) {
            if (isset($this->excludedParameters[$param->pascalName])) {
                continue;
            }
            $out[] = $param;
        }
        return $out;
    }

    /**
     * Convert `PascalCase` -> `camelCase` by lowercasing the first
     * character. The rest of the identifier is preserved verbatim so
     * `UseTls` -> `useTls` and `UseTLS` -> `useTLS`.
     * Callers that want a different spelling of embedded acronyms
     * should map at the emission site.
     */
    private static function pascalToCamel(string $pascal): string
    {
        if ($pascal === '') {
            return $pascal;
        }
        return \lcfirst($pascal);
    }

    /**
     * Map a Smithy endpoint-ruleset parameter type to its PHP scalar
     * equivalent. The ruleset spec supports `string`, `boolean`, and
     * `stringArray`. anything else falls back to `mixed`.
     */
    private static function mapType(string $rulesetType): string
    {
        return match (\strtolower($rulesetType)) {
            'boolean' => 'bool',
            'string' => 'string',
            'stringarray' => 'array',
            default => 'mixed'
        };
    }
}
