<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates a per-service `TypeRegistry` class that maps Smithy shape IDs
 * to the generated PHP classes for a service.
 *
 * The registry surfaces three lookups. `schemaFor(shapeId)` returns the
 * lazy `Schema` for a shape (via a `[Class, 'schema']` callable that
 * dispatches through the generated shape class's `schema()` static). The
 * counterpart `classFor(shapeId)` returns the PHP class-string, and
 * `shapeIdFor(class)` is the inverse mapping used by application code
 * that starts from a typed instance.
 *
 * Callers include the protocol layer (for error-shape dispatch, where the
 * wire `__type` discriminator is a Smithy shape ID) and downstream client
 * code that needs to resolve schemas from IDs it received off the wire.
 * Every entry in the emitted registry corresponds to a shape the
 * generator produced a PHP class for, so the maps are dense and complete
 * for the service closure.
 */
final class TypeRegistryGenerator
{
    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings
    ) {}

    /**
     * Build the registry for the given service shape ID.
     *
     * @param string $serviceShapeId Fully-qualified service shape ID
     * @param array<string, true> $serviceClosure Shape IDs already known
     *                      to be in the service closure (produced by CodeGenerator).
     */
    public function generate(string $serviceShapeId, array $serviceClosure): GeneratedFile
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

        $serviceName = SmithyModel::getShapeName($serviceShapeId);
        $shortName = $this->resolveServiceName($serviceName);
        $className = $shortName . 'TypeRegistry';

        // The registry lives at the top-level service namespace regardless
        // of the `flat` vs `categorized` organization mode. It's cross-
        // cutting metadata, not a shape.
        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        // Walk the service closure and collect shape IDs whose PHP class
        // the generator emits. Structures, unions, and enums produce
        // classes. lists / maps / scalars don't, and are excluded here.
        $entries = $this->collectShapeEntries($serviceClosure);

        $writer = new PhpCodeWriter($namespace);

        $writer->writeDocBlock([
            "Shape-ID registry for the {$shortName} service.",
            '',
            'Populated once at codegen time from the service closure. Callers',
            'use `schemaFor` for lazy schema resolution, `classFor` for a raw',
            'class-string when they need to reflect on the generated type, and',
            '`shapeIdFor` for the inverse lookup keyed by class.'
        ]);
        $writer->writeLine("final class {$className}");
        $writer->openBlock('{');

        // Both directions of the map share the same source-of-truth entries.
        // Each direction is emitted as its own literal so lookups stay O(1)
        // without runtime inversion.
        $writer->writeDocBlock(['@var array<string, class-string>']);
        $writer->writeLine('private const CLASSES_BY_SHAPE_ID = [');
        $writer->pushIndent();
        foreach ($entries as $entry) {
            $shapeId = $this->escapeString($entry['shapeId']);
            $classFqcn = $this->escapeString($entry['classFqcn']);
            $writer->writeLine("'{$shapeId}' => '{$classFqcn}',");
        }
        $writer->popIndent();
        $writer->writeLine('];');

        $writer->writeLine();
        $writer->writeDocBlock(['@var array<class-string, string>']);
        $writer->writeLine('private const SHAPE_IDS_BY_CLASS = [');
        $writer->pushIndent();
        foreach ($entries as $entry) {
            $shapeId = $this->escapeString($entry['shapeId']);
            $classFqcn = $this->escapeString($entry['classFqcn']);
            $writer->writeLine("'{$classFqcn}' => '{$shapeId}',");
        }
        $writer->popIndent();
        $writer->writeLine('];');

        $writer->writeLine();
        $writer->writeDocBlock([
            'Return the generated PHP class-string for a Smithy shape ID,',
            'or null when the ID is unknown to this service.',
            '',
            '@return class-string|null'
        ]);
        $writer->writeLine('public static function classFor(string $shapeId): ?string');
        $writer->openBlock('{');
        $writer->writeLine(
            'return self::CLASSES_BY_SHAPE_ID[$shapeId] ?? null;'
        );
        $writer->closeBlock();

        $writer->writeLine();
        $writer->writeDocBlock([
            'Return the Smithy shape ID for a generated class.',
            'Returns null when this codegen run did not produce the class.',
            '',
            '@param class-string $class'
        ]);
        $writer->writeLine('public static function shapeIdFor(string $class): ?string');
        $writer->openBlock('{');
        $writer->writeLine('return self::SHAPE_IDS_BY_CLASS[$class] ?? null;');
        $writer->closeBlock();

        $writer->writeLine();
        $writer->writeDocBlock([
            'Resolve a shape ID to its `Schema` object. Uses the generated',
            "class's lazy `schema()` static, so schemas are only built the",
            'first time they are accessed.',
            '',
            'Returns null when the shape ID is not registered.'
        ]);
        $writer->writeLine(
            'public static function schemaFor(string $shapeId): '
            . '?\\Smithy\\Core\\Serde\\Schema'
        );
        $writer->openBlock('{');
        $writer->writeLine(
            '$class = self::CLASSES_BY_SHAPE_ID[$shapeId] ?? null;'
        );
        $writer->writeLine('if ($class === null) {');
        $writer->pushIndent();
        $writer->writeLine('return null;');
        $writer->popIndent();
        $writer->writeLine('}');
        // method_exists() guards against future shape categories that omit
        // schema() (Unit and prelude-adjacent primitives already do). The
        // check keeps the registry usable without a hard runtime break.
        $writer->writeLine(
            'return \\method_exists($class, \'schema\')'
            . ' ? $class::schema() : null;'
        );
        $writer->closeBlock();

        $writer->writeLine();
        $writer->writeDocBlock([
            'Iterate every registered (shape ID, class-string) pair. Useful',
            'for building higher-level indexes on top of the registry (a',
            'category-scoped subset, a namespace filter, etc.).',
            '',
            '@return array<string, class-string>'
        ]);
        $writer->writeLine('public static function all(): array');
        $writer->openBlock('{');
        $writer->writeLine('return self::CLASSES_BY_SHAPE_ID;');
        $writer->closeBlock();

        $writer->closeBlock();


        $filePath = $outputDir . DIRECTORY_SEPARATOR . $className . '.php';
        return new GeneratedFile($filePath, $writer->toFileContent());
    }

    /**
     * Enumerate every closure member whose PHP class the generator emits,
     * pairing each shape ID with the fully-qualified class name.
     *
     * @param array<string, true> $serviceClosure
     * @return list<array{shapeId: string, classFqcn: string}>
     */
    private function collectShapeEntries(array $serviceClosure): array
    {
        $entries = [];
        foreach ($serviceClosure as $shapeId => $_) {
            $shape = $this->model->getShape($shapeId);
            if ($shape === null) {
                continue;
            }
            if (isset(($shape['traits'] ?? [])['smithy.api#mixin'])) {
                continue;
            }

            $type = $shape['type'] ?? '';
            if (!\in_array(
                $type,
                ['structure', 'union', 'enum', 'intEnum'],
                true
            )) {
                continue;
            }
            // Skip the prelude even inside the closure - the codegen
            // excludes it explicitly and its shapes have no matching
            // generated PHP class.
            if (\str_starts_with($shapeId, 'smithy.api#')) {
                continue;
            }

            $entries[] = [
                'shapeId' => $shapeId,
                'classFqcn' => \ltrim(
                    $this->symbolProvider->toClassName($shapeId),
                    '\\'
                )
            ];
        }

        // Deterministic output: shape IDs are the natural sort key and
        // keep diffs stable across regeneration runs.
        \usort(
            $entries,
            fn(array $a, array $b): int => \strcmp($a['shapeId'], $b['shapeId'])
        );

        return $entries;
    }

    /**
     * Escape single-quoted-string content for safe embedding in emitted
     * PHP source. Only backslashes and single quotes need attention here.
     * every shape ID and FQCN we produce is ASCII.
     */
    private function escapeString(string $value): string
    {
        return \str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
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

}
