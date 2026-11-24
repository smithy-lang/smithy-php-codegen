# Smithy PHP Codegen

Generates PHP clients, shapes, operation metadata, and runtime schemas from a
Smithy JSON AST. Requires PHP 8.2 or later.

## Installation

```shell
composer require --dev smithy/codegen
```

## Command Line

The generator accepts the JSON AST produced by a Smithy model build.

```shell
vendor/bin/smithy-php-codegen model.json \
    --namespace='Example\Weather' \
    --output=generated \
    --organization=categorized
```

The `categorized` organization writes inputs, outputs, operations, errors,
and shared shapes into separate subdirectories. The `flat` organization
writes generated classes directly under the configured namespace.

Pass a comma-separated operation set to generate a statically typed client
containing only those operations and their transitive dependencies:

```shell
vendor/bin/smithy-php-codegen model.json \
    --namespace='Example\Weather' \
    --output=generated \
    --operations=GetForecast,ListForecasts
```

A JSON configuration file supplies generator settings:

```shell
vendor/bin/smithy-php-codegen model.json --config=codegen.json
```

The equivalent JSON setting is:

```json
{
    "namespace": "Example\\Weather",
    "outputDir": "generated",
    "operationSet": [
        "GetForecast",
        "ListForecasts"
    ]
}
```

Entries may be operation names or fully-qualified Smithy shape IDs. Omitting
`operationSet` generates the complete client.

Array compatibility APIs are disabled by default. Enable each API for one or
more shape categories:

```shell
vendor/bin/smithy-php-codegen model.json \
    --namespace='Example\Weather' \
    --output=generated \
    --array-access=output \
    --array-serialization=all
```

`--array-access` adds read-only `ArrayAccess` through
`ReadonlyArrayAccessTrait`. `--array-serialization` adds `fromArray()` and
`toArray()` through `ArraySerializableTrait`. The array serialization methods
delegate to `ArrayShapeSerializer`.

Both options accept a comma-separated list containing `input`, `output`,
`error`, or `shape`. Use `all` to select every structure category.

The equivalent JSON settings are:

```json
{
    "namespace": "Example\\Weather",
    "outputDir": "generated",
    "arrayAccess": {
        "enabled": true,
        "categories": ["output"]
    },
    "arraySerialization": {
        "enabled": true,
        "categories": ["all"]
    }
}
```

An SDK can also configure both the generated service exception and its parent:

```json
{
    "namespace": "Example\\Weather",
    "outputDir": "generated",
    "serviceExceptionClass": "Example\\Weather\\Exception\\WeatherException",
    "baseServiceException": "Vendor\\Sdk\\SdkException"
}
```

## PHP API

```php
use Smithy\Codegen\CodeGenerator;
use Smithy\Codegen\CodegenSettings;
use Smithy\Codegen\Model\SmithyModel;

$model = SmithyModel::fromFile('model.json');
$settings = new CodegenSettings(
    namespace: 'Example\\Weather',
    outputDir: __DIR__ . '/generated',
);

(new CodeGenerator($settings, $model))->generateAndWrite();
```

## Integrations

Installed packages can extend code generation without replacing the generic
orchestrator. Integrations can preprocess the model, customize settings,
decorate symbol resolution, and contribute additional generated files.

```php
use Smithy\Codegen\CodegenContext;
use Smithy\Codegen\Integration\AbstractCodegenIntegration;

final class ExampleIntegration extends AbstractCodegenIntegration
{
    public function generateAdditionalFiles(CodegenContext $context): iterable
    {
        // Yield GeneratedFile instances beneath the configured output root.
        return [];
    }
}
```

Programmatic callers pass integration instances to `CodeGenerator`:

```php
new CodeGenerator($settings, $model, [new ExampleIntegration()]);
```

The CLI can instantiate Composer-autoloadable integrations from configuration:

```json
{
    "namespace": "Example\\Weather",
    "outputDir": "generated",
    "integrations": [
        {
            "class": "Vendor\\Codegen\\ExampleIntegration",
            "settings": {
                "exampleOption": true
            }
        }
    ]
}
```

Integration classes are trusted build-time code. They must have a public
zero-argument constructor. Integrations are ordered deterministically using
their names, priorities, and `runBefore()` or `runAfter()` dependencies.
Duplicate names, dependency cycles, and generated-path collisions are rejected.
Symbol-provider decorators may customize type mappings, short class names, and
property names, but generated classes remain in the namespaces selected by
codegen settings.

## Generated Output

The generator emits the enabled artifacts:

- structures, enums, and union variants
- operation metadata and service metadata
- generated service clients and client configuration classes
- centralized schema registries and type registries
- a per-service exception base and modeled service exceptions
- endpoint BDD bytecode and endpoint cache-key builders

Generated shapes contain protocol-independent serialization methods.
`smithy/codec` and `smithy/protocol` determine the wire format at runtime.

Every generated service includes a `<ServiceName>Exception`. Modeled errors
extend it, and protocols use it for unmodeled service errors. It extends
`Smithy\Core\Exception\ApiException` by default. `serviceExceptionClass`
changes the generated class name, while `baseServiceException` changes its
parent.

## Stability

For a fixed model and configuration, generation produces deterministic output.
By default, regeneration preserves existing schema member order in the same
output directory.

`generateAndWrite()` treats the configured output directory as managed
generated output. It renders the complete result in a sibling staging
directory and replaces the prior output only after every file is written.
This removes stale generated files while leaving the previous output intact
when generation or pre-commit publication fails. Existing output-directory
permissions are retained. A post-commit backup-cleanup failure leaves the new
output installed and reports the remaining backup path. Do not place
handwritten files in the configured output directory. For safety, a nonempty
existing directory is adopted only when `Schemas.php` contains the generated
registry signature. The output directory also cannot contain the process's
current working directory.

The generator accepts one non-prelude service per invocation. Provide a model
with one non-prelude service.

## Dependencies

The generator uses contracts from `smithy/client` and `smithy/core`. Generated
clients also require the runtime packages referenced by their configured
protocol and transport.

## Design

See [Code Generation Design](https://github.com/smithy-lang/smithy-php/blob/main/docs/design/codegen/smithy-php-code-generation.md).

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Codegen
```
