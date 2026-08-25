<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Client\Endpoint\Bytecode\Bytecode;
use Smithy\Codegen\CodegenSettings;

/**
 * Emits a per-service `<Service>EndpointKeyBuilder` class exposing a
 * static `build(): \Closure` factory that returns an inlined cache-key
 * builder for {@see \Smithy\Client\Endpoint\Bytecode\BytecodeEndpointResolver}.
 *
 * The generic resolver walks its snapshot of the ruleset's input-name
 * list on every miss to build a stable cache key. The generated closure
 * knows the register names statically and emits the key construction
 * inline.
 *
 * The wire format matches the generic loop exactly:
 *
 *   - Missing keys, nulls, booleans, strings, and integers have
 *     distinct tags.
 *   - Variable-width values carry their encoded length.
 *   - Other values use a length-prefixed serialized representation.
 *
 * A cross-generator equivalence test guarantees that the closure and
 * the generic loop produce identical output for every input map.
 */
final class EndpointKeyBuilderGenerator
{
    /** Suffix appended to the service short name to form the class name. */
    private const CLASS_SUFFIX = 'EndpointKeyBuilder';

    /**
     * @internal
     */
    public function __construct(
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Emit the key-builder class file.
     *
     * @param string $serviceShortName Short name matching sibling generated
     *                                 service files.
     * @param Bytecode $bytecode Compiled bytecode whose `inputRegisterMap`
     *                           defines key-concatenation order.
     */
    public function generate(string $serviceShortName, Bytecode $bytecode): GeneratedFile
    {
        $className = $serviceShortName . self::CLASS_SUFFIX;
        $namespace = $this->settings->resolveEndpointNamespace();
        $outputDir = $this->settings->resolveEndpointOutputDir();

        $source = $this->renderSource(
            namespace: $namespace,
            className: $className,
            inputNames: \array_keys($bytecode->inputRegisterMap)
        );

        return new GeneratedFile(
            path: $outputDir . \DIRECTORY_SEPARATOR . $className . '.php',
            content: $source
        );
    }

    /**
     * @param list<string> $inputNames
     */
    private function renderSource(
        string $namespace,
        string $className,
        array $inputNames
    ): string
    {
        $body = $this->renderClosureBody($inputNames);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * Generated cache-key builder for {$className}.";
        $lines[] = ' *';
        $lines[] = ' * The returned closure matches resolver output,';
        $lines[] = ' * but skips the reflective walk over the';
        $lines[] = ' * ruleset\'s input-name list.';
        $lines[] = ' *';
        $lines[] = ' * @generated Do not edit.';
        $lines[] = ' */';
        $lines[] = "final class {$className}";
        $lines[] = '{';
        $lines[] = '    private static ?\\Closure $closure = null;';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * Return the memoized cache-key builder closure.';
        $lines[] = '     *';
        $lines[] = '     * @return \\Closure(array<string, mixed>): string';
        $lines[] = '     */';
        $lines[] = '    public static function build(): \\Closure';
        $lines[] = '    {';
        $lines[] = '        return self::$closure ??= static function (';
        $lines[] = '            array $inputs';
        $lines[] = '        ): string';
        $lines[] = '        {';
        $lines[] = $body;
        $lines[] = '        };';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return \implode("\n", $lines);
    }

    /**
     * Build an inline closure body that mirrors the runtime key format.
     *
     * @param list<string> $inputNames
     */
    private function renderClosureBody(array $inputNames): string
    {
        if ($inputNames === []) {
            return "            return '';";
        }

        $lines = ['            $key = \'\';'];
        foreach ($inputNames as $name) {
            $escaped = \addslashes($name);
            $lines[] = '            if (!\\array_key_exists(';
            $lines[] = "                '{$escaped}',";
            $lines[] = '                $inputs';
            $lines[] = '            )) {';
            $lines[] = '                $key .= "\x00";';
            $lines[] = '            } else {';
            $lines[] = "                \$value = \$inputs['{$escaped}'];";
            $lines[] = '                if ($value === null) {';
            $lines[] = '                    $key .= "\x01";';
            $lines[] = '                } elseif ($value === false) {';
            $lines[] = '                    $key .= "\x02";';
            $lines[] = '                } elseif ($value === true) {';
            $lines[] = '                    $key .= "\x03";';
            $lines[] = '                } elseif (\is_string($value)) {';
            $lines[] = '                    $key .= "\x04"';
            $lines[] = '                        . \strlen($value)';
            $lines[] = '                        . \':\' . $value;';
            $lines[] = '                } elseif (\is_int($value)) {';
            $lines[] = '                    $key .= "\x05" . $value . \';\';';
            $lines[] = '                } else {';
            $lines[] = '                    $encoded = \serialize($value);';
            $lines[] = '                    $key .= "\x06"';
            $lines[] = '                        . \strlen($encoded)';
            $lines[] = '                        . \':\' . $encoded;';
            $lines[] = '                }';
            $lines[] = '            }';
        }
        $lines[] = '            return $key;';

        return \implode("\n", $lines);
    }
}
