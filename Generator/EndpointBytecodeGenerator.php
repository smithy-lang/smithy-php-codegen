<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Client\Endpoint\Bytecode\Compiler\BddCompiler;
use Smithy\Client\Endpoint\Bytecode\Compiler\BytecodeSourceGenerator;
use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;
use Smithy\Client\Endpoint\Bytecode\StdFunctions;
use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;

/**
 * Emits the compiled endpoint-rules bytecode class for a service.
 *
 * The Smithy `smithy.rules#endpointBdd` trait (already optimized upstream
 * by the smithy-java rules-engine) is compiled once at codegen time into
 * a self-contained PHP class exposing a memoized `Bytecode` singleton via
 * `<ServiceShortName>EndpointBytecode::get()`. That class is what
 * customer clients wire into `BytecodeEndpointResolver` at runtime -
 * there is no runtime compilation and no ruleset JSON on disk.
 *
 * {@see generate()} returns `null` when the service has no
 * `endpointBdd` trait.
 *
 * The function registry is supplied by the caller via
 * {@see CodegenSettings::$rulesFunctions}. The Smithy runtime seeds it
 * with {@see StdFunctions::all()}. Callers can add protocol or vendor
 * functions through settings.
 */
final class EndpointBytecodeGenerator
{
    /** Fully-qualified trait id we look for on the service shape. */
    private const ENDPOINT_BDD_TRAIT = 'smithy.rules#endpointBdd';

    /** Suffix appended to the service short name to form the class name. */
    private const CLASS_SUFFIX = 'EndpointBytecode';

    private readonly BytecodeSourceGenerator $sourceGenerator;
    private readonly EndpointKeyBuilderGenerator $keyBuilderGenerator;

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly CodegenSettings $settings
    ) {
        $this->sourceGenerator = new BytecodeSourceGenerator();
        $this->keyBuilderGenerator = new EndpointKeyBuilderGenerator($settings);
    }

    /**
     * Compile + emit the bytecode class for the given service.
     *
     * Returns `null` when the service shape has no `endpointBdd` trait -
     * the orchestrator treats that as "nothing to generate", not an error.
     * When the trait is present and
     * {@see CodegenSettings::$generateEndpointKeyBuilder} is enabled,
     * a companion `<Service>EndpointKeyBuilder` file is produced by
     * {@see self::generateAll()}. Callers that only need the bytecode
     * file can keep using this method. callers that want the full set
     * should call {@see self::generateAll()}.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID.
     * @param string|null $sourceModelPath Optional model-file path
     *                    printed in the generated file's `@source` provenance tag.
     */
    public function generate(
        string $serviceShapeId,
        ?string $sourceModelPath = null
    ): ?GeneratedFile
    {
        $files = $this->generateAll($serviceShapeId, $sourceModelPath);
        return $files === [] ? null : $files[0];
    }

    /**
     * Compile + emit every endpoint-related file the settings ask for.
     *
     * Always returns:
     *   - `[0]`: `<Service>EndpointBytecode.php`
     *
     * When {@see CodegenSettings::$generateEndpointKeyBuilder} is on,
     * additionally returns:
     *   - `[1]`: `<Service>EndpointKeyBuilder.php`
     *
     * Returns `[]` when the service has no `endpointBdd` trait - the
     * orchestrator treats that as "nothing to generate", not an error.
     * @return list<GeneratedFile>
     */
    public function generateAll(string $serviceShapeId, ?string $sourceModelPath = null): array
    {
        $shape = $this->model->getShape($serviceShapeId);
        if ($shape === null) {
            throw new \InvalidArgumentException(
                "Shape not found: {$serviceShapeId}"
            );
        }
        if (($shape['type'] ?? '') !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceShapeId} is not a service"
            );
        }

        $trait = $this->model->getTrait(
            $serviceShapeId,
            self::ENDPOINT_BDD_TRAIT
        );
        if (!\is_array($trait)) {
            return [];
        }

        $shortName = $this->resolveServiceShortName($serviceShapeId);
        $className = $shortName . self::CLASS_SUFFIX;
        $namespace = $this->settings->resolveEndpointNamespace();
        $outputDir = $this->settings->resolveEndpointOutputDir();

        $compiler = new BddCompiler(
            $this->normalizedFunctionRegistry(),
            $this->normalizedExtendedOpcodes(),
            $this->settings->assumeAsciiEndpointStrings
        );
        $bytecode = $compiler->compile($trait);

        $source = $this->sourceGenerator->generate(
            $bytecode,
            namespace: $namespace,
            className: $className,
            sourceModel: $sourceModelPath ?? ''
        );

        $files = [
            new GeneratedFile(
                path: $outputDir . \DIRECTORY_SEPARATOR . $className . '.php',
                content: $source
            )
        ];

        if ($this->shouldEmitKeyBuilder()) {
            $files[] = $this->keyBuilderGenerator->generate(
                $shortName,
                $bytecode
            );
        }

        return $files;
    }

    /**
     * Check whether endpoint key builder generation is enabled.
     */
    private function shouldEmitKeyBuilder(): bool
    {
        return $this->settings->generateEndpointKeyBuilder;
    }

    /**
     * Prefer the short segment of the configured namespace so the class
     * name lines up with
     * the sibling `<ShortName>Service` and `<ShortName>TypeRegistry`
     * files. Falls back to the model's own short name when the
     * namespace has no discriminating final segment.
     */
    private function resolveServiceShortName(string $serviceShapeId): string
    {
        $parts = \explode('\\', $this->settings->namespace);
        $lastPart = \end($parts);
        if (\is_string($lastPart) && $lastPart !== '') {
            return $lastPart;
        }
        return SmithyModel::getShapeName($serviceShapeId);
    }

    /**
     * The compiler indexes functions by name. Whatever shape the caller
     * hands us (list, name-keyed map, mixed), normalize to `[name => fn]`
     * so ordering-sensitive tests get a stable structure and we crash
     * loudly on a bad entry rather than at compile time.
     *
     * The Smithy standard library is always present. Caller-supplied
     * functions override standard functions with the same name.
     *
     * @return array<string, RulesFunctionInterface>
     */
    private function normalizedFunctionRegistry(): array
    {
        $out = StdFunctions::all();
        foreach ($this->settings->rulesFunctions as $key => $fn) {
            if (!$fn instanceof RulesFunctionInterface) {
                throw new \InvalidArgumentException(
                    'CodegenSettings::$rulesFunctions must contain '
                    . 'RulesFunctionInterface; got '
                    . \get_debug_type($fn) . " for key {$key}"
                );
            }
            $out[$fn->getName()] = $fn;
        }
        return $out;
    }

    /**
     * Return caller-provided function opcode mappings.
     *
     * @return array<string, int>
     */
    private function normalizedExtendedOpcodes(): array
    {
        $out = [];
        foreach ($this->settings->extendedOpcodes as $name => $op) {
            if (!\is_int($op)) {
                throw new \InvalidArgumentException(
                    'CodegenSettings::$extendedOpcodes must map fn-name '
                    . 'to int opcode; got '
                    . \get_debug_type($op) . " for key {$name}"
                );
            }
            $out[$name] = $op;
        }
        return $out;
    }

}
