<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

/**
 * Immutable value object describing one endpoint-ruleset parameter, in
 * the form the client-config generator needs to emit a constructor
 * argument, property, and endpoint-resolver static-context entry.
 *
 * Produced by {@see EndpointRulesetIntrospector}. consumed by
 * {@see ClientConfigGenerator}.
 */
final class EndpointRulesetParameter
{
    /**
     * @internal
     */
    public function __construct(
        /**
         * Original ruleset name in PascalCase, kept verbatim so the
         * generated `staticContext` map keys match what the endpoint
         * bytecode expects (`UseTls`, `EndpointId`, ...).
         */
        public readonly string $pascalName,
        /**
         * PHP property/argument name - the pascal name with the first
         * letter lowercased. Acronyms like `UseTLS` land as `useTLS`
         * verbatim. callers style that at emission time if needed.
         */
        public readonly string $phpPropertyName,
        /**
         * PHP type hint: `string`, `bool`, `array`, or `mixed`. The
         * generator wraps this with `?` when the parameter is not
         * required and has no default.
         */
        public readonly string $phpType,
        /**
         * Ruleset-declared default value. Kept as a raw PHP scalar (or
         * null) so the generator can literal-emit it in the constructor
         * argument list.
         */
        public readonly mixed $default = null,
        /**
         * Whether the ruleset marks the parameter as required. Required
         * parameters without a default become non-nullable constructor
         * arguments. optional ones become nullable + defaulted.
         */
        public readonly bool $required = false,
        /**
         * Optional Smithy `builtIn` label. Kept for diagnostics and
         * ruleset introspection.
         */
        public readonly ?string $builtIn = null,
        /**
         * Documentation string from the ruleset. Cleaned and used as
         * the emitted argument's docblock body.
         */
        public readonly ?string $documentation = null
    ) {}
}
