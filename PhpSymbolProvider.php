<?php

declare(strict_types=1);

namespace Smithy\Codegen;

use Smithy\Codegen\Model\SmithyModel;

/**
 * Maps Smithy shape types to PHP type representations.
 *
 * This is the PHP equivalent of smithy-java's JavaSymbolProvider - it resolves
 * Smithy types into PHP type strings used in generated code.
 */
final class PhpSymbolProvider
{
    /**
     * PHP keywords, compile-time constants, and reserved type names that
     * cannot be emitted as bare class names, plus PHP's soft-reserved names.
     *
     * Soft-reserved names are included so generated clients remain valid if
     * PHP assigns those names language meaning in a future release. Matching
     * is case-insensitive because PHP class names and reserved words are
     * case-insensitive.
     *
     * When a shape's short name lands on this list, {@see toShortClassName()}
     * prefixes it with the current service short name (`StorageObject`,
     * `ComputeResource`, etc.) so the emitted PHP is legal.
     *
     * @see https://www.php.net/manual/en/reserved.keywords.php
     * @see https://www.php.net/manual/en/reserved.other-reserved-words.php
     */
    private const RESERVED_CLASS_NAMES = [
        // Language keywords.
        '__halt_compiler',
        'abstract', 'and', 'array', 'as',
        'break',
        'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
        'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'eval', 'exit', 'extends',
        'final', 'finally', 'fn', 'for', 'foreach', 'function',
        'global', 'goto',
        'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset',
        'list',
        'match',
        'namespace', 'new',
        'or',
        'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return',
        'static', 'switch',
        'throw', 'trait', 'try',
        'unset', 'use',
        'var',
        'while',
        'xor',
        'yield',
        // Compile-time constants.
        '__class__', '__dir__', '__file__', '__function__', '__line__',
        '__method__', '__namespace__', '__property__', '__trait__',
        // Other reserved type and literal names.
        'parent', 'self',
        'int', 'float', 'bool', 'string', 'true', 'false', 'null',
        'void', 'iterable', 'object', 'mixed', 'never',
        // Soft-reserved for possible future language use.
        'enum', 'resource', 'numeric'
    ];

    /**
     * Disambiguation map: shape ID -> resolved short PHP class name.
     *
     * Smithy shape IDs are namespaced (`com.example#Foo`), but PHP doesn't
     * support two classes with the same short name in the same namespace.
     * When multiple shape IDs collapse to the same short name, the first
     * (alphabetical order) keeps the bare name and the rest get a
     * PascalCased prefix derived from the last namespace segment.
     *
     * The map is built once at construction so every call site sees a
     * stable result, and so the disambiguation is deterministic across
     * regenerations.
     *
     * @var array<string, string>
     */
    private array $shortNameMap;

    /**
     * Service name used to prefix reserved-word shape names.
     */
    private readonly string $servicePrefix;

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly CodegenSettings $settings
    ) {
        $this->servicePrefix = $this->resolveServicePrefix();
        $this->shortNameMap = $this->buildShortNameMap();
    }

    /**
     * Resolve the service name from the configured output namespace.
     */
    private function resolveServicePrefix(): string
    {
        $ns = $this->settings->namespace;
        if ($ns === '') {
            return '';
        }
        $parts = \explode('\\', $ns);
        $last = \end($parts);
        return $last !== false ? $last : '';
    }


    /**
     * Walk every shape in the model, group by bare short name, and assign
     * disambiguating prefixes when more than one shape shares a short name.
     *
     * Reserved-word rewrites happen *before* collision detection so the
     * rewritten form (`StorageObject`, `ComputeResource`, ...) participates in
     * the disambiguation set. Without this ordering, two shapes both
     * named `Object` in the same service would end up with a fresh
     * collision on their rewritten `StorageObject` form and produce
     * regenerated-namespace prefixes on top of the service prefix,
     * which nobody would enjoy reading.
     *
     * @return array<string, string>
     */
    private function buildShortNameMap(): array
    {
        // Phase 1: assign each shape ID its candidate short name - the
        // reserved-name rewrite if applicable, otherwise the bare Smithy
        // shape name.
        $reservedLookup = \array_flip(self::RESERVED_CLASS_NAMES);
        $candidate = [];
        foreach (\array_keys($this->model->getShapes()) as $shapeId) {
            $bare = SmithyModel::getShapeName($shapeId);
            $candidate[$shapeId] = isset($reservedLookup[\strtolower($bare)])
                    && $this->servicePrefix !== ''
                ? $this->servicePrefix . $bare
                : $bare;
        }

        // Phase 2: bucket shapes by candidate short name so collisions
        // (including any produced by the reserved rewrite) get resolved
        // together.
        $byShortName = [];
        foreach ($candidate as $shapeId => $short) {
            $byShortName[$short][] = $shapeId;
        }

        $resolved = [];
        foreach ($byShortName as $short => $shapeIds) {
            if (\count($shapeIds) === 1) {
                $resolved[$shapeIds[0]] = $short;
                continue;
            }
            // Stable ordering so regenerations produce the same names.
            \sort($shapeIds);
            // First shape ID wins the bare name. the rest get a
            // namespace-derived prefix.
            $resolved[$shapeIds[0]] = $short;
            for ($i = 1; $i < \count($shapeIds); $i++) {
                $resolved[$shapeIds[$i]] =
                    $this->namespacePrefix($shapeIds[$i]) . $short;
            }
        }

        return $resolved;
    }

    /**
     * PascalCased last namespace segment used as a disambiguating prefix.
     */
    private function namespacePrefix(string $shapeId): string
    {
        $namespace = SmithyModel::getShapeNamespace($shapeId);
        $segments = \explode('.', $namespace);
        $last = \end($segments) ?: '';
        return \ucfirst($last);
    }

    /**
     * Resolve the PHP type for a given shape ID.
     *
     * @param string $shapeId Fully-qualified Smithy shape ID
     * @return PhpType The resolved PHP type information
     */
    public function toPhpType(string $shapeId): PhpType
    {
        $shapeType = $this->model->getShapeType($shapeId);

        return match ($shapeType) {
            'string' => new PhpType('string', builtIn: true),
            'boolean' => new PhpType('bool', builtIn: true),
            'byte',
            'short',
            'integer',
            'long' => new PhpType('int', builtIn: true),
            'float', 'double' => new PhpType('float', builtIn: true),
            'bigInteger' => new PhpType(
                'BigInteger',
                builtIn: false,
                fqcn: 'Smithy\\Core\\Shape\\BigInteger'
            ),
            'bigDecimal' => new PhpType(
                'BigDecimal',
                builtIn: false,
                fqcn: 'Smithy\\Core\\Shape\\BigDecimal'
            ),
            'blob' => new PhpType('string', builtIn: true),
            'timestamp' => new PhpType('float', builtIn: true),
            'document' => new PhpType(
                'DocumentInterface',
                builtIn: false,
                fqcn: 'Smithy\\Core\\Shape\\Document\\DocumentInterface'
            ),
            'list' => $this->resolveListType($shapeId),
            'map' => $this->resolveMapType($shapeId),
            'structure' => $this->resolveStructureType($shapeId),
            'union' => $this->resolveUnionType($shapeId),
            'enum' => $this->resolveEnumType($shapeId),
            'intEnum' => $this->resolveEnumType($shapeId),
            default => new PhpType('mixed', builtIn: true)
        };
    }

    /**
     * Resolve the PHP type for a member of a structure/union.
     *
     * @param string $parentShapeId The parent shape ID
     * @param string $memberName The member name
     * @return PhpType
     */
    public function toMemberType(string $parentShapeId, string $memberName): PhpType
    {
        $targetId = $this->model->getMemberTarget($parentShapeId, $memberName);
        if ($targetId === null) {
            return new PhpType('mixed', builtIn: true);
        }

        // Blob members with @httpPayload -> StreamInterface (pass stream
        // directly, never buffer)
        $targetType = $this->model->getShapeType($targetId);
        if ($targetType === 'blob') {
            $members = $this->model->getMembers($parentShapeId);
            $memberTraits = $members[$memberName]['traits'] ?? [];
            if (isset($memberTraits['smithy.api#httpPayload'])) {
                return new PhpType(
                    'string|StreamInterface',
                    builtIn: false,
                    fqcn: 'Psr\\Http\\Message\\StreamInterface'
                );
            }
        }

        return $this->toPhpType($targetId);
    }

    /**
     * Convert a Smithy member name to a PHP property name (camelCase).
     *
     * @param string $memberName Smithy member name
     * @return string PHP property name
     */
    public function toPropertyName(string $memberName): string
    {
        // Smithy member names are typically camelCase already.
        // Ensure the first character is lowercase.
        return lcfirst($memberName);
    }

    /**
     * Get the fully-qualified PHP class name for a shape.
     *
     * @param string $shapeId
     * @return string
     */
    public function toClassName(string $shapeId): string
    {
        // `smithy.api#Unit` is the one prelude shape codegen actually
        // emits (see CodeGenerator::generateUnit). It's used as both an
        // input and output by different operations in the same service,
        // so category-driven placement would resolve to `Input\Unit` in
        // one caller and `Output\Unit` in another - but the file lands
        // in the shape namespace exactly once. Force the shape category
        // for this ID so every caller imports the same class.
        if ($shapeId === 'smithy.api#Unit') {
            $namespace = $this->settings->resolveNamespace(
                ShapeCategory::Shape
            );
            return $namespace . '\\Unit';
        }

        $shapeName = $this->toShortClassName($shapeId);
        $category = $this->model->categorizeShape($shapeId);
        $namespace = $this->settings->resolveNamespace($category);

        return $namespace . '\\' . $shapeName;
    }

    /**
     * Get just the short class name for a shape.
     *
     * Returns the disambiguated short name from {@see $shortNameMap} when
     * the shape participates in a name collision, otherwise the bare
     * shape name. Always returns the bare name for shape IDs the model
     * doesn't carry (a defensive fallback).
     *
     * Error shapes always land here with an `Exception` suffix, because
     * PHP convention treats `class Foo extends \Throwable` code as
     * ill-formed without it. Keeping the suffix rule in one place -
     * here, at the symbol provider - is critical: every downstream
     * generator (operation error arrays, structure `extends` clauses,
     * schema registry, exception factories) has to agree on the class
     * name, and the previous approach of re-implementing the check in
     * each generator produced silent skew (e.g. an operation importing
     * `ResourceAlreadyExists` while the structure emitted
     * `ResourceAlreadyExistsException`, resulting in a runtime
     * `Class not found` when the error was thrown).
     *
     * Configured service exceptions that already carry the suffix are
     * preserved.
     *
     * @param string $shapeId
     * @return string
     */
    public function toShortClassName(string $shapeId): string
    {
        $name = $this->shortNameMap[$shapeId] ?? SmithyModel::getShapeName(
            $shapeId
        );
        if ($this->model->categorizeShape($shapeId) === ShapeCategory::Error
            && !\str_ends_with($name, 'Exception')
        ) {
            return $name . 'Exception';
        }
        return $name;
    }

    private function resolveListType(string $shapeId): PhpType
    {
        $shape = $this->model->getShape($shapeId);
        $memberTarget = $shape['member']['target'] ?? null;

        $docType = 'array';
        if ($memberTarget !== null) {
            $memberType = $this->toPhpType($memberTarget);
            $elementType = $this->toCollectionDocType($memberType);
            if ($this->model->hasTrait(
                $shapeId,
                'smithy.api#sparse'
            )) {
                $elementType .= '|null';
            }
            $docType = "list<{$elementType}>";
        }

        return new PhpType('array', builtIn: true, docType: $docType);
    }

    private function resolveMapType(string $shapeId): PhpType
    {
        $shape = $this->model->getShape($shapeId);
        $keyTarget = $shape['key']['target'] ?? null;
        $valueTarget = $shape['value']['target'] ?? null;

        $docType = 'array';
        if ($keyTarget !== null && $valueTarget !== null) {
            $keyType = $this->toPhpType($keyTarget);
            $valueType = $this->toPhpType($valueTarget);
            $keyDocType = $this->toCollectionDocType($keyType);
            $valueDocType = $this->toCollectionDocType($valueType);
            if ($this->model->hasTrait(
                $shapeId,
                'smithy.api#sparse'
            )) {
                $valueDocType .= '|null';
            }
            $docType = "array<{$keyDocType}, {$valueDocType}>";
        }

        return new PhpType('array', builtIn: true, docType: $docType);
    }

    private function toCollectionDocType(PhpType $type): string
    {
        if ($type->docType !== null) {
            return $type->docType;
        }

        return $type->fqcn === null
            ? $type->typeHint
            : '\\' . $type->fqcn;
    }

    private function resolveStructureType(string $shapeId): PhpType
    {
        $className = $this->toClassName($shapeId);
        $shortName = $this->toShortClassName($shapeId);

        return new PhpType($shortName, builtIn: false, fqcn: $className);
    }

    private function resolveUnionType(string $shapeId): PhpType
    {
        $className = $this->toClassName($shapeId);
        $shortName = $this->toShortClassName($shapeId);

        return new PhpType($shortName, builtIn: false, fqcn: $className);
    }

    private function resolveEnumType(string $shapeId): PhpType
    {
        $className = $this->toClassName($shapeId);
        $shortName = $this->toShortClassName($shapeId);

        return new PhpType($shortName, builtIn: false, fqcn: $className);
    }
}
