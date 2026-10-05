<?php

declare(strict_types=1);

namespace Smithy\Codegen;

/**
 * Represents a resolved PHP type for use in code generation.
 */
final class PhpType
{
    /**
     * @param string $typeHint PHP type hint to use in generated code.
     * @param bool $builtIn Whether this is a PHP built-in type.
     * @param string|null $fqcn Fully-qualified class name for non-built-in types.
     * @param string|null $docType PHPDoc type when richer than the type hint.
     */
    public function __construct(
        public readonly string $typeHint,
        public readonly bool $builtIn = false,
        public readonly ?string $fqcn = null,
        public readonly ?string $docType = null
    ) {}

    /**
     * Get the nullable type hint (e.g., "?string", "?Human", "float|null").
     *
     * Uses `?` prefix for single types, appends `|null` for union types.
     */
    public function nullable(): string
    {
        if ($this->typeHint === 'mixed') {
            return 'mixed';
        }

        // Union types can't use `?` prefix - append `|null` if not already
        // present.
        if (str_contains($this->typeHint, '|')) {
            return str_contains($this->typeHint, '|null')
                || str_ends_with($this->typeHint, 'null')
                ? $this->typeHint
                : $this->typeHint . '|null';
        }

        return '?' . $this->typeHint;
    }

    /**
     * Whether this type requires a `use` import statement.
     */
    public function requiresImport(): bool
    {
        return !$this->builtIn && $this->fqcn !== null;
    }

    /**
     * Get the nullable doc type for PHPDoc annotations.
     */
    public function nullableDocType(): string
    {
        $type = $this->docType ?? $this->typeHint;
        return self::hasTopLevelNull($type)
            ? $type
            : $type . '|null';
    }

    private static function hasTopLevelNull(string $type): bool
    {
        $depth = 0;
        $start = 0;
        $length = \strlen($type);

        for ($i = 0; $i < $length; $i++) {
            if ($type[$i] === '<') {
                $depth++;
            } elseif ($type[$i] === '>') {
                $depth--;
            } elseif ($type[$i] === '|' && $depth === 0) {
                if (\trim(\substr($type, $start, $i - $start)) === 'null') {
                    return true;
                }
                $start = $i + 1;
            }
        }

        return \trim(\substr($type, $start)) === 'null';
    }
}
