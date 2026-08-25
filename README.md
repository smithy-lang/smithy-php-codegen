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

A JSON configuration file supplies generator settings:

```shell
vendor/bin/smithy-php-codegen model.json --config=codegen.json
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

## Generated Output

The generator emits the enabled artifacts:

- structures, enums, and union variants
- operation metadata and service metadata
- generated service clients and client configuration classes
- centralized schema registries and type registries
- modeled service exceptions
- endpoint BDD bytecode and endpoint cache-key builders

Generated shapes contain protocol-independent serialization methods.
`smithy/codec` and `smithy/protocol` determine the wire format at runtime.

## Stability

For a fixed model and configuration, generation produces deterministic output.
By default, regeneration preserves existing schema member order in the same
output directory.

The generator accepts one non-prelude service per invocation. Provide a model
with one non-prelude service.

## Dependencies

The generator uses contracts from `smithy/client` and `smithy/core`. Generated
clients also require the runtime packages referenced by their configured
protocol and transport.

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Codegen
```
