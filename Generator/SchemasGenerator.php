<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;
use Smithy\Codegen\PhpSymbolProviderInterface;
use Smithy\Codegen\PriorCodegenEvaluator;
use Smithy\Codegen\Writer\PhpCodeWriter;

/**
 * Generates a centralized Schemas class for a service.
 *
 * The Schemas class contains all schema objects as public static properties,
 * with per-shape lazy init methods. This eliminates per-shape schema storage
 * and enables direct cross-shape schema references.
 *
 * Pattern:
 *   Schemas::$CreateWidgetInput   - the structure schema
 *   Schemas::createWidgetInput()  - lazy init method
 */
final class SchemasGenerator
{
    /**
     * Identifies a schema registry emitted by this generator.
     *
     * @internal
     */
    public const GENERATED_FILE_SIGNATURE =
        'Centralized schema registry for all shapes in this service.';

    /**
     * Short class name of the sibling class that holds shared list and map
     * member schemas. Kept OFF the `Schemas` class because `Schemas`
     * declares a `public static Schema $X` property for every shape short
     * name, and Smithy allows a shape literally named `collectionMembers`
     * (property names are case-sensitive, so `$CollectionMembers` clashes
     * too). A sibling class in the service root namespace cannot collide:
     * generated shape classes live in sub-namespaces, never the root.
     */
    private const SHARED_MEMBERS_CLASS = 'SchemasMembers';

    private const DIRECT_MEMBER_TRAITS_BY_ID = [
        'smithy.rules#contextParam' => true,
    ];

    /** Map from model trait string IDs -> Schema::T_* int constants */
    private const TRAIT_INDEX_MAP = [
        'smithy.api#xmlName' => 0,
        'smithy.api#xmlNamespace' => 1,
        'smithy.api#xmlAttribute' => 2,
        'smithy.api#xmlFlattened' => 3,
        'smithy.api#timestampFormat' => 4,
        'smithy.api#httpHeader' => 5,
        'smithy.api#httpLabel' => 6,
        'smithy.api#httpPayload' => 7,
        'smithy.api#httpPrefixHeaders' => 8,
        'smithy.api#httpQuery' => 9,
        'smithy.api#httpQueryParams' => 10,
        'smithy.api#httpResponseCode' => 11,
        'smithy.api#jsonName' => 12,
        'smithy.api#mediaType' => 13,
        'smithy.api#required' => 14,
        'smithy.api#sparse' => 15,
        'smithy.api#streaming' => 16,
        'smithy.api#idempotencyToken' => 19,
        'smithy.api#sensitive' => 20,
        'smithy.api#error' => 23,
        'smithy.api#readonly' => 24,
        'smithy.api#idempotent' => 25,
        'smithy.api#retryable' => 26
    ];

    /** Traits that should propagate from target to member schema if not already set */
    private const PROPAGATE_TRAITS = [
        'smithy.api#timestampFormat',
        'smithy.api#xmlNamespace',
        'smithy.api#xmlAttribute',
        'smithy.api#streaming',
        // @mediaType propagates so the runtime can detect base64 encoding
        // on `@httpHeader` members whose target string carries the trait
        // (the HttpStructBindingExtensionProvider reads it off the member).
        'smithy.api#mediaType',
        // @sparse lives on the list/map *shape*, but the runtime serializer
        // / deserializer only sees the member schema when iterating a
        // struct's members. Propagating to the member lets it check
        // `T_SPARSE` without an extra `$schema->target` resolve.
        'smithy.api#sparse',
        // @sensitive lives on the target shape (for example `SecretString`).
        // Propagate so generated member schemas trip runtime redaction checks
        // without resolving the target schema first.
        'smithy.api#sensitive'
    ];

    private ?PhpCodeWriter $activeWriter = null;

    /** @var array<string, string> Shape ID => rendered class reference */
    private array $targetClassReferences = [];

    /** @var array<string, string> Shape ID => target class FQCN */
    private array $targetClassFqcns = [];

    /** @var array<string, true> Imported target class FQCNs */
    private array $importedTargetClasses = [];

    /** @var array<string, mixed> Struct and union shapes with a root method */
    private array $rootShapes = [];

    /** @var array<string, array<string, true>> Shape ID => reachable shape IDs */
    private array $reachable = [];

    /**
     * @internal
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly PhpSymbolProviderInterface $symbolProvider,
        private readonly CodegenSettings $settings,
        /**
         * Prior-generation evaluator. When non-null (regeneration
         * with `preserveExistingMemberOrder` on), each shape's
         * member emission walks in the historical order - new
         * members added by the model land at the tail so the
         * `getMembersByIndex()` positions and constructor slot
         * layout stay ABI-stable.
         */
        private readonly ?PriorCodegenEvaluator $priorCodegen = null
    ) {}

    /**
     * Generate the Schemas class containing all schema objects for the service.
     *
     * @param array<string, true> $serviceClosure All shape IDs to generate schemas for
     * @return GeneratedFile
     */
    public function generate(array $serviceClosure): GeneratedFile
    {
        $namespace = $this->settings->namespace;
        $outputDir = $this->settings->outputDir;

        $writer = new PhpCodeWriter($namespace);
        $writer->addImport('Smithy\\Core\\Serde\\Schema');
        $writer->addImport('Smithy\\Core\\Shape\\ShapeType');
        $this->prepareTargetClassReferences($writer);

        $writer->writeDocBlock([
            self::GENERATED_FILE_SIGNATURE,
            '',
            'Stores Schema objects as lazy public static properties.',
            'Each structure has a dedicated member initializer.'
        ]);
        $writer->writeLine('final class Schemas');
        $writer->openBlock('{');

        // Collect all structure shapes that need schemas
        $structureShapes = [];
        foreach ($this->model->getStructureShapes() as $shapeId => $shape) {
            if (!isset($serviceClosure[$shapeId])) {
                continue;
            }
            // Skip mixins
            $traits = $shape['traits'] ?? [];
            if (isset($traits['smithy.api#mixin'])) {
                continue;
            }
            $structureShapes[$shapeId] = $shape;
        }

        // Collect all union shapes reachable from the service. Unions share
        // the same lazy-registry contract as structures: their per-shape
        // `schema()` methods are proxies onto `Schemas::foo()`, so the
        // Schema graph collapses to one instance per shape per process.
        // Prior to this the union path allocated a fresh `new Schema(...)`
        // per call.
        $unionShapes = [];
        foreach ($this->model->getUnionShapes() as $shapeId => $shape) {
            if (!isset($serviceClosure[$shapeId])) {
                continue;
            }
            $unionShapes[$shapeId] = $shape;
        }


        // Collect enum and intEnum shapes that need schemas. Enums get a
        // top-level entry so the generated class can dispatch through its
        // own schema when serialized standalone (outside a parent struct's
        // serializeMembers()).
        $enumShapes = [];
        foreach ($this->model->getEnumShapes() as $shapeId => $shape) {
            if (!isset($serviceClosure[$shapeId])) {
                continue;
            }
            $enumShapes[$shapeId] = $shape;
        }
        $this->rootShapes = $structureShapes + $unionShapes;

        // Write all static property declarations. The symbol provider's
        // `toShortClassName()` centralizes the error-suffix rule, so this
        // path - and every other reference to the same class name in the
        // codebase - stays in agreement without a local rewrite.
        foreach ($structureShapes as $shapeId => $shape) {
            $className = $this->symbolProvider->toShortClassName($shapeId);
            $writer->writeLine("public static Schema \${$className};");
        }
        foreach ($unionShapes as $shapeId => $shape) {
            $className = $this->symbolProvider->toShortClassName($shapeId);
            $writer->writeLine("public static Schema \${$className};");
        }
        foreach ($enumShapes as $shapeId => $shape) {
            $className = $this->symbolProvider->toShortClassName($shapeId);
            $writer->writeLine("public static Schema \${$className};");
        }
        $writer->writeLine();

        // Write lazy init methods for each structure
        foreach ($structureShapes as $shapeId => $shape) {
            $this->writeInitMethod($writer, $shapeId);
            $writer->writeLine();
        }

        // Write lazy init methods for each union.
        foreach ($unionShapes as $shapeId => $shape) {
            $this->writeUnionInitMethod($writer, $shapeId);
            $writer->writeLine();
        }

        // Write lazy init methods for each enum / intEnum
        foreach ($enumShapes as $shapeId => $shape) {
            $this->writeEnumInitMethod($writer, $shapeId, $shape);
            $writer->writeLine();
        }


        $writer->closeBlock();

        // Shared list/map member schema cache, kept off `Schemas` to avoid
        // colliding with a shape named `collectionMembers`. See
        // self::SHARED_MEMBERS_CLASS.
        $writer->writeLine();
        $writer->writeDocBlock([
            'Shared list and map member schemas for this service, keyed by '
                . 'member ID.',
            '',
            'Held here rather than on Schemas so no generated shape property '
                . 'can collide with it.'
        ]);
        $writer->writeLine(
            'final class ' . self::SHARED_MEMBERS_CLASS
        );
        $writer->openBlock('{');
        $writer->writeLine('/** @var array<string, Schema> */');
        $writer->writeLine('public static array $shared = [];');
        $writer->closeBlock();

        $filePath = $outputDir . DIRECTORY_SEPARATOR . 'Schemas.php';

        return new GeneratedFile(
            path: $filePath,
            content: $writer->toFileContent()
        );
    }

    /**
     * Write the lazy init method for a single structure shape.
     */
    private function writeInitMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        // Symbol provider centralizes the error-suffix rule.
        $className = $this->symbolProvider->toShortClassName($shapeId);

        // Method name: camelCase version of className
        $methodName = lcfirst($className);

        $members = $this->getMembersIndexed($shapeId);

        $rootTraits = $this->buildRootTraits(
            $shapeId,
            [
                'smithy.api#xmlName',
                'smithy.api#xmlNamespace',
                'smithy.api#error',
                'smithy.api#retryable',
                'smithy.api#sensitive'
            ]
        );
        $rootTraitsStr = $this->renderTraitsArray($rootTraits);
        $rootTraitsByIdStr = $this->renderTraitsById($rootTraits);

        $writer->writeLine("public static function {$methodName}(): Schema");
        $writer->openBlock('{');
        $writer->writeLine("if (isset(self::\${$className})) {");
        $writer->pushIndent();
        $writer->writeLine("return self::\${$className};");
        $writer->popIndent();
        $writer->writeLine('}');
        $writer->writeLine();

        // Resolve the FQCN for the generated structure class so the root
        // schema can carry `targetClass`. ArrayShapeSerializer reads this on
        // the root schema to instantiate the shape from an associative
        // array without the caller having to pass `static::class`.
        $rootTargetClass = $this->targetClassReference($shapeId);

        // Build the structure schema with inline member schemas
        $writer->writeLine("return self::\${$className} = new Schema(");
        $writer->pushIndent();
        $writer->writeLine("id: '{$shapeId}',");
        $writer->writeLine("type: ShapeType::Structure,");
        $writer->writeLine("members: [");
        $writer->pushIndent();

        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $schemaStr = $this->buildMemberSchemaExpression(
                $shapeId,
                $memberName,
                $memberDef,
                $index
            );
            $writer->writeLine("{$schemaStr},");
            $index++;
        }

        $writer->popIndent();
        $writer->writeLine(
            "]{$rootTraitsStr}{$rootTraitsByIdStr},"
        );
        $writer->writeLine("targetClass: {$rootTargetClass}::class,");
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Write the lazy init method for a single union shape.
     *
     * Structurally identical to `writeInitMethod` except:
     *   - `type: ShapeType::Union`
     *   - `members[]` uses variant memberName/memberIndex numbering
     *     starting at 0 in declaration order (no required/defaulted
     *     ordering - unions have exactly one active variant per
     *     instance, so the concept doesn't apply)
     *
     * The generated `UnionGenerator` emits a `schema()` method that
     * proxies onto `Schemas::foo()` (mirroring how struct schemas already
     * work), so the Schema graph collapses to one instance per shape per
     * process.
     */
    private function writeUnionInitMethod(PhpCodeWriter $writer, string $shapeId): void
    {
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $methodName = lcfirst($className);
        $rootTargetClass = $this->targetClassReference($shapeId);
        // Unions honor declaration order rather than the
        // required-then-optional bucketing structs use. every variant is
        // exclusive so bucketing has no meaning here.
        $members = $this->model->getMembers($shapeId);
        $rootTraits = $this->buildRootTraits(
            $shapeId,
            ['smithy.api#sensitive']
        );
        $rootTraitsStr = $this->renderTraitsArray($rootTraits);
        $rootTraitsByIdStr = $this->renderTraitsById($rootTraits);

        $writer->writeLine("public static function {$methodName}(): Schema");
        $writer->openBlock('{');
        $writer->writeLine("if (isset(self::\${$className})) {");
        $writer->pushIndent();
        $writer->writeLine("return self::\${$className};");
        $writer->popIndent();
        $writer->writeLine('}');
        $writer->writeLine();

        $writer->writeLine("return self::\${$className} = new Schema(");
        $writer->pushIndent();
        $writer->writeLine("id: '{$shapeId}',");
        $writer->writeLine("type: ShapeType::Union,");
        $writer->writeLine('members: [');
        $writer->pushIndent();

        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $schemaStr = $this->buildMemberSchemaExpression(
                $shapeId,
                $memberName,
                $memberDef,
                $index
            );
            $writer->writeLine("{$schemaStr},");
            $index++;
        }

        $writer->popIndent();
        $writer->writeLine(
            "]{$rootTraitsStr}{$rootTraitsByIdStr},"
        );
        $writer->writeLine("targetClass: {$rootTargetClass}::class,");
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Write the lazy init method for a single enum / intEnum shape.
     *
     * Enums get a top-level Schema entry so the generated class can dispatch
     * standalone (outside a parent struct's `serializeMembers()`) through
     * its own schema. The schema carries the shape's traits and the
     * `targetClass` so {@see Schema::resolveTarget()} and
     * {@see \Smithy\Core\Shape\Utils\ArrayShapeSerializer::fromArray()} can
     * route to the enum's `fromValue()` factory.
     */
    private function writeEnumInitMethod(
        PhpCodeWriter $writer,
        string $shapeId,
        array $shape
    ): void
    {
        $className = $this->symbolProvider->toShortClassName($shapeId);
        $methodName = lcfirst($className);
        $shapeType = $shape['type'] ?? 'enum';
        $typeEnum = $shapeType === 'intEnum'
            ? 'ShapeType::IntEnum'
            : 'ShapeType::Enum';

        $rootTraits = $this->buildRootTraits(
            $shapeId,
            ['smithy.api#xmlName', 'smithy.api#xmlNamespace']
        );
        $rootTraitsStr = $this->renderTraitsArray($rootTraits);
        $rootTraitsByIdStr = $this->renderTraitsById($rootTraits);

        $rootTargetClass = $this->targetClassReference($shapeId);

        $writer->writeLine("public static function {$methodName}(): Schema");
        $writer->openBlock('{');
        $writer->writeLine("if (isset(self::\${$className})) {");
        $writer->pushIndent();
        $writer->writeLine("return self::\${$className};");
        $writer->popIndent();
        $writer->writeLine('}');
        $writer->writeLine();

        $writer->writeLine("return self::\${$className} = new Schema(");
        $writer->pushIndent();
        $writer->writeLine("id: '{$shapeId}',");
        $writer->writeLine(
            "type: {$typeEnum}{$rootTraitsStr}{$rootTraitsByIdStr},"
        );
        $writer->writeLine("targetClass: {$rootTargetClass}::class,");
        $writer->popIndent();
        $writer->writeLine(');');
        $writer->closeBlock();
    }

    /**
     * Build the Schema constructor expression for a member.
     */
    private function buildMemberSchemaExpression(
        string $parentShapeId,
        string $memberName,
        array $memberDef,
        int $index
    ): string
    {
        $memberId = $parentShapeId . '$' . $memberName;
        $targetId = $memberDef['target'] ?? 'smithy.api#String';
        $targetType = $this->model->getShapeType($targetId) ?? 'string';
        $shapeTypeEnum = $this->smithyTypeToEnum($targetType);

        // Build traits
        $memberTraits = $memberDef['traits'] ?? [];
        $traits = $this->buildMemberTraits($memberTraits);
        $this->propagateTargetTraits($targetId, $traits);
        $traitsStr = $this->renderTraitsArray($traits);
        $traitsByIdStr = $this->renderTraitsById($traits);
        $directTraitsByIdStr = $this->renderDirectTraitsById(
            $memberTraits,
            self::DIRECT_MEMBER_TRAITS_BY_ID
        );

        // Build collection sub-schema parameters
        $collectionParams = $this->buildCollectionParams(
            $targetId,
            $targetType,
            [$parentShapeId => true]
        );

        // Include inline members for struct/union targets (needed for
        // deserialization)
        $unionMembers = $this->buildInlineMembers(
            $targetId,
            $targetType,
            $parentShapeId,
            [$parentShapeId => true]
        );

        // Emit targetClass for any member that needs runtime class dispatch
        // (ArrayShapeSerializer::fromArray reads it to recurse into nested
        // struct/union members and ::fromValue() on nested enums).
        $targetClassStr = $this->buildTargetClassParam($targetId, $targetType);
        $phpName = $this->buildPhpNameParam($memberName);

        return "new Schema(id: '{$memberId}', "
            . "type: {$shapeTypeEnum}, "
            . "memberName: '{$memberName}', "
            . "memberIndex: {$index}{$phpName}"
            . "{$unionMembers}{$collectionParams}{$traitsStr}"
            . "{$traitsByIdStr}{$directTraitsByIdStr}{$targetClassStr})";
    }

    /**
     * Build the optional generated PHP member-name parameter.
     */
    private function buildPhpNameParam(string $memberName): string
    {
        $phpName = $this->symbolProvider->toPropertyName($memberName);

        return $phpName === \lcfirst($memberName)
            ? ''
            : ", phpName: '{$phpName}'";
    }

    /**
     * Get all members in canonical constructor order (required -> defaulted ->
     * optional). Iteration index matches the assigned `memberIndex`.
     */
    private function getMembersIndexed(string $shapeId): array
    {
        return $this->model->getMembersOrdered(
            $shapeId,
            $this->priorCodegen?->memberOrderFor($shapeId)
        );
    }


    private function smithyTypeToEnum(string $type): string
    {
        return match ($type) {
            'structure' => 'ShapeType::Structure',
            'union' => 'ShapeType::Union',
            'enum' => 'ShapeType::Enum',
            'intEnum' => 'ShapeType::IntEnum',
            'list' => 'ShapeType::List_',
            'map' => 'ShapeType::Map',
            'string' => 'ShapeType::String_',
            'integer' => 'ShapeType::Integer',
            'long' => 'ShapeType::Long',
            'short' => 'ShapeType::Short',
            'byte' => 'ShapeType::Byte',
            'float' => 'ShapeType::Float_',
            'double' => 'ShapeType::Double',
            'boolean' => 'ShapeType::Boolean',
            'timestamp' => 'ShapeType::Timestamp',
            'blob' => 'ShapeType::Blob',
            'bigInteger' => 'ShapeType::BigInteger',
            'bigDecimal' => 'ShapeType::BigDecimal',
            'document' => 'ShapeType::Document',
            default => 'ShapeType::String_'
        };
    }

    /**
     * Build serde-relevant traits for a member.
     */
    private function buildMemberTraits(array $memberTraits): array
    {
        $serdeTraits = [];
        foreach ($this->traitIndexMap() as $traitId => $index) {
            if (\array_key_exists($traitId, $memberTraits)) {
                $serdeTraits[$traitId] = $memberTraits[$traitId];
            }
        }
        return $serdeTraits;
    }

    /**
     * Propagate serialization-relevant traits from the target shape to the member traits.
     */
    private function propagateTargetTraits(string $targetId, array &$traits): void
    {
        $targetShape = $this->model->getShape($targetId);
        if ($targetShape === null) {
            return;
        }
        $targetTraits = $this->buildRootTraits(
            $targetId,
            self::PROPAGATE_TRAITS
        );
        $propagatedTraits = \array_fill_keys(
            self::PROPAGATE_TRAITS,
            true
        ) + \array_diff_key(
            $this->configuredTraitIds(),
            self::TRAIT_INDEX_MAP
        );
        foreach ($propagatedTraits as $traitId => $_) {
            if (!\array_key_exists($traitId, $traits)
                && \array_key_exists($traitId, $targetTraits)
            ) {
                $traits[$traitId] = $targetTraits[$traitId];
            }
        }
    }

    /**
     * Render populated dense-trait indexes without null padding.
     */
    private function renderTraitsArray(array $traits): string
    {
        if (empty($traits)) {
            return '';
        }

        $indexed = [];
        $traitIndexMap = $this->traitIndexMap();
        foreach ($traits as $traitId => $value) {
            $idx = $traitIndexMap[$traitId] ?? null;
            if ($idx === null) {
                continue;
            }
            $indexed[$idx] = $value;
        }

        if ($indexed === []) {
            return '';
        }

        \ksort($indexed);
        $parts = [];
        foreach ($indexed as $index => $value) {
            $parts[] = $index . ' => ' . $this->exportTraitLiteral($value);
        }

        return ', traits: [' . implode(', ', $parts) . ']';
    }

    /**
     * Render configured traits keyed by their shape IDs.
     *
     * @param array<string, mixed> $traits
     */
    private function renderTraitsById(array $traits): string
    {
        $parts = [];
        foreach ($this->configuredTraitIds() as $traitId => $_) {
            if (!\array_key_exists($traitId, $traits)) {
                continue;
            }
            $parts[] = PhpCodeWriter::exportLiteral($traitId)
                . ' => '
                . $this->exportTraitLiteral($traits[$traitId]);
        }

        return $parts === []
            ? ''
            : ', traitsById: [' . \implode(', ', $parts) . ']';
    }

    /**
     * @param array<string, mixed> $traits
     * @param array<string, true> $preservedTraits
     */
    private function renderDirectTraitsById(
        array $traits,
        array $preservedTraits
    ): string
    {
        $parts = [];
        $configuredTraits = $this->configuredTraitIds();
        $preservedTraits += $configuredTraits;
        foreach ($preservedTraits as $traitId => $_) {
            if (!\array_key_exists($traitId, $traits)) {
                continue;
            }
            $value = isset($configuredTraits[$traitId])
                ? $this->exportTraitLiteral($traits[$traitId])
                : PhpCodeWriter::exportLiteral($traits[$traitId]);
            $parts[] = PhpCodeWriter::exportLiteral($traitId)
                . ' => '
                . $value;
        }

        return $parts === []
            ? ''
            : ', directTraitsById: [' . \implode(', ', $parts) . ']';
    }

    /**
     * Render a value using the same marker-trait normalization as the dense
     * trait table.
     */
    private function exportTraitLiteral(mixed $value): string
    {
        if ($value === [] || $value === (object)[] || $value === null) {
            return 'true';
        }

        return PhpCodeWriter::exportLiteral($value);
    }

    /**
     * @return array<string, true>
     */
    private function configuredTraitIds(): array
    {
        return \array_fill_keys(
            \array_keys($this->settings->schemaTraitIndices),
            true
        );
    }

    /**
     * Build the root traits retained by generated schemas.
     *
     * @param list<string> $builtInTraitIds
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function buildRootTraits(
        string $shapeId,
        array $builtInTraitIds,
        array $visited = []
    ): array
    {
        if (isset($visited[$shapeId])) {
            return [];
        }
        $visited[$shapeId] = true;

        $shape = $this->model->getShape($shapeId) ?? [];
        $preservedTraits = \array_fill_keys($builtInTraitIds, true)
            + $this->configuredTraitIds();
        $rootTraits = [];

        foreach ($shape['mixins'] ?? [] as $mixin) {
            $mixinId = \is_array($mixin)
                ? $mixin['target'] ?? null
                : null;
            if (!\is_string($mixinId)) {
                continue;
            }
            $mixinShape = $this->model->getShape($mixinId) ?? [];
            $mixinTraits = $this->buildRootTraits(
                $mixinId,
                $builtInTraitIds,
                $visited
            );
            unset($mixinTraits['smithy.api#mixin']);
            $mixinTrait = $mixinShape['traits']['smithy.api#mixin'] ?? null;
            $localTraits = \is_array($mixinTrait)
                ? $mixinTrait['localTraits'] ?? []
                : [];
            if (\is_array($localTraits)) {
                foreach ($localTraits as $traitId) {
                    if (\is_string($traitId)) {
                        unset($mixinTraits[$traitId]);
                    }
                }
            }
            foreach ($preservedTraits as $traitId => $_) {
                if (\array_key_exists($traitId, $mixinTraits)) {
                    $rootTraits[$traitId] = $mixinTraits[$traitId];
                }
            }
        }

        $shapeTraits = $shape['traits'] ?? [];
        foreach ($preservedTraits as $traitId => $_) {
            if (\array_key_exists($traitId, $shapeTraits)) {
                $rootTraits[$traitId] = $shapeTraits[$traitId];
            }
        }

        return $rootTraits;
    }

    /**
     * @return array<string, int>
     */
    private function traitIndexMap(): array
    {
        return self::TRAIT_INDEX_MAP
            + $this->settings->schemaTraitIndices;
    }

    /**
     * Build listMember/mapKey/mapValue parameters for list and map types.
     *
     * @param array<string, true> $visited Cycle detection set
     */
    private function buildCollectionParams(
        string $targetId,
        string $targetType,
        array $visited = []
    ): string
    {
        // On a cycle, keep the key and value members so the collection stays
        // resolvable, but stop inlining so the schema stays finite.
        $cut = isset($visited[$targetId]);
        $visited[$targetId] = true;

        $targetShape = $this->model->getShape($targetId);
        if ($targetShape === null) {
            return '';
        }

        if ($targetType === 'list') {
            $memberTarget =
                $targetShape['member']['target'] ?? 'smithy.api#String';
            $memberType = $this->model->getShapeType($memberTarget) ?? 'string';
            $memberTypeEnum = $this->smithyTypeToEnum($memberType);
            $memberTraits = $targetShape['member']['traits'] ?? [];
            $listMemberTraits = $this->buildMemberTraits($memberTraits);
            $this->propagateTargetTraits(
                $memberTarget,
                $listMemberTraits
            );
            $listMemberTraitsStr = $this->renderTraitsArray($listMemberTraits);
            $listMemberTraitsByIdStr = $this->renderTraitsById(
                $listMemberTraits
            );
            $listMemberDirectTraitsByIdStr = $this->renderDirectTraitsById(
                $memberTraits,
                self::DIRECT_MEMBER_TRAITS_BY_ID
            );
            $memberMembers = $cut ? '' : $this->buildInlineMembers(
                $memberTarget,
                $memberType,
                $targetId,
                $visited
            );
            $memberCollectionParams = $cut ? '' : $this->buildCollectionParams(
                $memberTarget,
                $memberType,
                $visited
            );
            $memberTargetClass = $this->buildTargetClassParam(
                $memberTarget,
                $memberType
            );
            $listMemberId = $targetId . '$member';

            return ', listMember: '
                . $this->sharedCollectionMember($targetId, $listMemberId)
                . "new Schema(id: '{$listMemberId}', "
                . "type: {$memberTypeEnum}, memberName: 'member', "
                . "memberIndex: 0"
                . "{$memberMembers}{$memberCollectionParams}"
                . "{$listMemberTraitsStr}{$listMemberTraitsByIdStr}"
                . "{$listMemberDirectTraitsByIdStr}"
                . "{$memberTargetClass})";
        }

        if ($targetType === 'map') {
            $keyTarget = $targetShape['key']['target'] ?? 'smithy.api#String';
            $keyType = $this->model->getShapeType($keyTarget) ?? 'string';
            $keyTypeEnum = $this->smithyTypeToEnum($keyType);

            $valueTarget =
                $targetShape['value']['target'] ?? 'smithy.api#String';
            $valueType = $this->model->getShapeType($valueTarget) ?? 'string';
            $valueTypeEnum = $this->smithyTypeToEnum($valueType);

            $keyTraits = $targetShape['key']['traits'] ?? [];
            $valueTraits = $targetShape['value']['traits'] ?? [];
            $builtKeyTraits = $this->buildMemberTraits($keyTraits);
            $this->propagateTargetTraits($keyTarget, $builtKeyTraits);
            $keyTraitsStr = $this->renderTraitsArray($builtKeyTraits);
            $keyTraitsByIdStr = $this->renderTraitsById($builtKeyTraits);
            $keyDirectTraitsByIdStr = $this->renderDirectTraitsById(
                $keyTraits,
                self::DIRECT_MEMBER_TRAITS_BY_ID
            );
            $builtValueTraits = $this->buildMemberTraits($valueTraits);
            $this->propagateTargetTraits(
                $valueTarget,
                $builtValueTraits
            );
            $valueTraitsStr = $this->renderTraitsArray($builtValueTraits);
            $valueTraitsByIdStr = $this->renderTraitsById(
                $builtValueTraits
            );
            $valueDirectTraitsByIdStr = $this->renderDirectTraitsById(
                $valueTraits,
                self::DIRECT_MEMBER_TRAITS_BY_ID
            );
            $valueMembers = $cut ? '' : $this->buildInlineMembers(
                $valueTarget,
                $valueType,
                $targetId,
                $visited
            );
            $valueCollectionParams = $cut ? '' : $this->buildCollectionParams(
                $valueTarget,
                $valueType,
                $visited
            );
            $valueTargetClass = $this->buildTargetClassParam(
                $valueTarget,
                $valueType
            );
            $mapKeyId = $targetId . '$key';
            $mapValueId = $targetId . '$value';

            return ', mapKey: '
                . $this->sharedCollectionMember($targetId, $mapKeyId)
                . "new Schema(id: '{$mapKeyId}', "
                . "type: {$keyTypeEnum}, memberName: 'key', memberIndex: 0"
                . "{$keyTraitsStr}"
                . "{$keyTraitsByIdStr}{$keyDirectTraitsByIdStr}), "
                . 'mapValue: '
                . $this->sharedCollectionMember($targetId, $mapValueId)
                . "new Schema(id: '{$mapValueId}', "
                . "type: {$valueTypeEnum}, memberName: 'value', "
                . "memberIndex: 1"
                . "{$valueMembers}{$valueCollectionParams}"
                . "{$valueTraitsStr}{$valueTraitsByIdStr}"
                . "{$valueDirectTraitsByIdStr}"
                . "{$valueTargetClass})";
        }

        return '';
    }

    /**
     * Build the `??=` prefix that caches a list or map member schema.
     *
     * Outside a cycle, a collection's member schema depends only on the
     * collection shape, so every use site shares one instance. On a cycle,
     * the cut point depends on the use site, so each site keeps its own.
     */
    private function sharedCollectionMember(
        string $collectionId,
        string $memberId
    ): string
    {
        if ($this->reaches($collectionId, $collectionId)) {
            return '';
        }

        return self::SHARED_MEMBERS_CLASS
            . "::\$shared['{$memberId}'] ??= ";
    }

    /**
     * Return true when `$fromId` reaches `$toId` through member targets.
     */
    private function reaches(string $fromId, string $toId): bool
    {
        if (!isset($this->reachable[$fromId])) {
            $seen = [];
            $pending = $this->memberTargets($fromId);
            while ($pending !== []) {
                $id = \array_pop($pending);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    \array_push($pending, ...$this->memberTargets($id));
                }
            }
            $this->reachable[$fromId] = $seen;
        }

        return isset($this->reachable[$fromId][$toId]);
    }

    /**
     * @return list<string> Shape IDs targeted by the members of `$shapeId`.
     */
    private function memberTargets(string $shapeId): array
    {
        $shape = $this->model->getShape($shapeId) ?? [];

        return match ($shape['type'] ?? null) {
            'structure', 'union' => \array_column(
                $this->model->getMembers($shapeId),
                'target'
            ),
            'list' => [$shape['member']['target'] ?? 'smithy.api#String'],
            'map' => [
                $shape['key']['target'] ?? 'smithy.api#String',
                $shape['value']['target'] ?? 'smithy.api#String'
            ],
            default => []
        };
    }

    /**
     * Count the root schemas that calling `self::method($targetId)` builds
     * eagerly: the target itself plus every root shape (struct or union)
     * reachable from it through member targets. A root's own `schema()`
     * method allocates that root plus its member schemas, so this is the
     * number of roots materialized on a single operation's cold path when
     * the member shares the target instead of copying one level.
     *
     * Used as an upper bound: reachability through a cycle-cut copy
     * overcounts, which only makes the caller fall back to the one-level
     * copy more often, never less. That keeps the single-op allocation at
     * or below the inline copy it replaces.
     */
    private function acyclicRootBuildCount(string $targetId): int
    {
        $count = isset($this->rootShapes[$targetId]) ? 1 : 0;
        foreach (\array_keys($this->reachableFrom($targetId)) as $reachedId) {
            if (isset($this->rootShapes[$reachedId])) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Return the set of shape IDs reachable from `$fromId` through member
     * targets, memoized via {@see reaches()}.
     *
     * @return array<string, true>
     */
    private function reachableFrom(string $fromId): array
    {
        // Prime the memo, then read it back.
        $this->reaches($fromId, $fromId);

        return $this->reachable[$fromId] ?? [];
    }

    /**
     * Build inline members for a structure/union target.
     *
     * Shares the target's canonical member schemas
     * (`self::method()->getMembers()`) only when the target cannot reach
     * `$containerId` (so the eager call cannot recurse into a frame on the
     * stack) AND the target's acyclic root build count is no larger than a
     * one-level inline copy. The second guard bounds the single-operation
     * cold path: sharing never materializes more roots than the copy it
     * replaces, while whole-service builds still collapse to one instance
     * per shape. Larger targets, and members on a cycle, are copied one
     * level instead.
     *
     * @param array<string, true> $visited Cycle detection set
     */
    private function buildInlineMembers(
        string $targetId,
        string $targetType,
        string $containerId,
        array $visited = []
    ): string
    {
        if ($targetType !== 'structure' && $targetType !== 'union') {
            return '';
        }

        if (isset($visited[$targetId])) {
            return '';
        }
        $visited[$targetId] = true;

        $members = $targetType === 'structure'
            ? $this->getMembersIndexed($targetId)
            : $this->model->getMembers($targetId);
        if (empty($members)) {
            return '';
        }

        if (isset($this->rootShapes[$targetId])
            && !$this->reaches($targetId, $containerId)
            && $this->acyclicRootBuildCount($targetId) <= \count($members)
        ) {
            // Acyclic struct/union target whose eager build is no larger
            // than a one-level inline copy: share the canonical member
            // objects. `self::method()` builds the target's acyclic root
            // closure, and the guard above caps that at the copy's own
            // member count, so a single operation's cold path never
            // allocates more than it did with the inline copy, while whole
            // -service builds still collapse to one instance per shape.
            $method = \lcfirst(
                $this->symbolProvider->toShortClassName($targetId)
            );

            return ", members: self::{$method}()->getMembers()";
        }

        $parts = [];
        $index = 0;
        foreach ($members as $memberName => $memberDef) {
            $memberId = $targetId . '$' . $memberName;
            $memTargetId = $memberDef['target'] ?? 'smithy.api#String';
            $memTargetType =
                $this->model->getShapeType($memTargetId) ?? 'string';
            $memTypeEnum = $this->smithyTypeToEnum($memTargetType);
            $directMemTraits = $memberDef['traits'] ?? [];
            $memTraits = $this->buildMemberTraits($directMemTraits);
            $this->propagateTargetTraits($memTargetId, $memTraits);
            $memTraitsStr = $this->renderTraitsArray($memTraits);
            $memTraitsByIdStr = $this->renderTraitsById($memTraits);
            $memDirectTraitsByIdStr = $this->renderDirectTraitsById(
                $directMemTraits,
                self::DIRECT_MEMBER_TRAITS_BY_ID
            );
            $memCollectionParams = $this->buildCollectionParams(
                $memTargetId,
                $memTargetType,
                $visited
            );
            $targetClassStr = $this->buildTargetClassParam(
                $memTargetId,
                $memTargetType
            );
            $phpName = $this->buildPhpNameParam($memberName);
            $parts[] = "new Schema(id: '{$memberId}', "
                . "type: {$memTypeEnum}, "
                . "memberName: '{$memberName}', "
                . "memberIndex: {$index}{$phpName}"
                . "{$memCollectionParams}{$memTraitsStr}"
                . "{$memTraitsByIdStr}{$memDirectTraitsByIdStr}"
                . "{$targetClassStr})";
            $index++;
        }

        return ', members: [' . implode(', ', $parts) . ']';
    }

    /**
     * Build a `, targetClass: Foo::class` constructor argument fragment
     * for shape types that have a generated PHP class. Returns an empty
     * string for primitive targets that need no runtime class dispatch.
     *
     * The runtime reads `Schema::$targetClass` for two purposes today:
     * recursive schema resolution (`Schema::resolveTarget()`) and
     * `ArrayShapeSerializer::fromArray()` instantiation of nested struct,
     * union, and enum members.
     */
    private function buildTargetClassParam(
        string $targetId,
        string $targetType
    ): string
    {
        if (!\in_array(
            $targetType,
            ['structure', 'union', 'enum', 'intEnum'],
            true
        )) {
            return '';
        }
        $targetClass = $this->targetClassReference($targetId);

        return ", targetClass: {$targetClass}::class";
    }

    /**
     * Precompute collision-safe target-class references.
     */
    private function prepareTargetClassReferences(
        PhpCodeWriter $writer
    ): void
    {
        $this->activeWriter = $writer;
        $this->targetClassReferences = [];
        $this->targetClassFqcns = [];
        $this->importedTargetClasses = [];

        /** @var array<string, array<string, list<string>>> $groups */
        $groups = [];
        $shapeIds = ['smithy.api#Unit'];
        foreach ($this->model->getShapes() as $shapeId => $shape) {
            if (!\in_array(
                $shape['type'] ?? null,
                ['structure', 'union', 'enum', 'intEnum'],
                true
            ) || isset($shape['traits']['smithy.api#mixin'])) {
                continue;
            }
            $shapeIds[] = $shapeId;
        }

        foreach ($shapeIds as $shapeId) {
            $fqcn = $this->symbolProvider->toClassName($shapeId);
            $shortName = $this->symbolProvider->toShortClassName($shapeId);
            $groups[$shortName][$fqcn][] = $shapeId;
        }
        \ksort($groups);

        $reservedNames = [
            'Schema' => true,
            'Schemas' => true,
            'ShapeType' => true
        ];
        $rawNames = \array_fill_keys(\array_keys($groups), true);
        $usedNames = $reservedNames;

        foreach ($groups as $shortName => $classes) {
            \ksort($classes);
            $useShortName = !isset($usedNames[$shortName]);
            foreach ($classes as $fqcn => $classShapeIds) {
                if ($useShortName) {
                    $reference = $shortName;
                    $usedNames[$reference] = true;
                    $useShortName = false;
                } else {
                    $reference = $this->allocateTargetClassAlias(
                        $shortName,
                        $usedNames,
                        $rawNames
                    );
                }

                foreach ($classShapeIds as $shapeId) {
                    $this->targetClassReferences[$shapeId] = $reference;
                    $this->targetClassFqcns[$shapeId] = $fqcn;
                }
            }
        }
    }

    /**
     * Allocate an alias that cannot collide with another generated class.
     *
     * @param array<string, true> $usedNames
     * @param array<string, true> $rawNames
     */
    private function allocateTargetClassAlias(
        string $shortName,
        array &$usedNames,
        array $rawNames
    ): string
    {
        $base = $shortName . 'Target';
        $alias = $base;
        $suffix = 2;
        while (isset($usedNames[$alias]) || isset($rawNames[$alias])) {
            $alias = $base . $suffix;
            ++$suffix;
        }
        $usedNames[$alias] = true;

        return $alias;
    }

    /**
     * Register and return the class reference for a generated target.
     */
    private function targetClassReference(string $shapeId): string
    {
        $reference = $this->targetClassReferences[$shapeId]
            ?? throw new \LogicException(
                "No target class reference was prepared for {$shapeId}"
            );
        $fqcn = $this->targetClassFqcns[$shapeId];

        if (!isset($this->importedTargetClasses[$fqcn])) {
            $writer = $this->activeWriter
                ?? throw new \LogicException(
                    'Target classes can only be imported during generation'
                );
            $shortName = $this->symbolProvider->toShortClassName($shapeId);
            $writer->addImport(
                $fqcn,
                $reference === $shortName ? null : $reference
            );
            $this->importedTargetClasses[$fqcn] = true;
        }

        return $reference;
    }
}
