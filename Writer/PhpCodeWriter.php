<?php

declare(strict_types=1);

namespace Smithy\Codegen\Writer;

/**
 * PHP code generation writer with indentation management and import tracking.
 */
final class PhpCodeWriter
{
    /** Maximum width for generated declarations. */
    public const MAX_GENERATED_LINE_WIDTH = 120;

    /**
     * Default docblock text width. 117 characters of content plus the
     * three-character ` * ` prefix yields a 120-column budget.
     */
    public const DOC_WRAP_WIDTH = 117;

    private string $buffer = '';
    private int $indentLevel = 0;
    private string $indentString = '    ';

    /** @var array<string, string> FQCN => short name */
    private array $imports = [];

    private string $namespace = '';

    /**
     * @internal
     */
    public function __construct(string $namespace = '')
    {
        $this->namespace = $namespace;
    }

    /**
     * Add a use import statement.
     *
     * @param string $fqcn Fully-qualified class name
     * @param string|null $alias Optional alias
     *
     * @return void
     */
    public function addImport(string $fqcn, ?string $alias = null): void
    {
        if (str_starts_with($fqcn, '\\')) {
            $fqcn = ltrim($fqcn, '\\');
        }

        $fqcnNamespace = $this->extractNamespace($fqcn);
        $fqcnShortName = $this->extractShortName($fqcn);
        if ($fqcnNamespace === $this->namespace
            && ($alias === null || $alias === $fqcnShortName)
        ) {
            return;
        }

        $shortName = $alias ?? $fqcnShortName;
        $this->imports[$fqcn] = $shortName;
    }

    /**
     * Write a line of code (with automatic indentation).
     */
    public function writeLine(string $line = ''): self
    {
        if ($line === '') {
            $this->buffer .= "\n";
        } else {
            $this->buffer .= $this->indent() . $line . "\n";
        }
        return $this;
    }

    /**
     * Write text without a trailing newline.
     */
    public function write(string $text): self
    {
        if ($this->buffer === '' || str_ends_with($this->buffer, "\n")) {
            $this->buffer .= $this->indent();
        }
        $this->buffer .= $text;
        return $this;
    }

    /**
     * Open a block (write line + increase indent).
     */
    public function openBlock(string $line): self
    {
        $this->writeLine($line);
        $this->indentLevel++;
        return $this;
    }

    /**
     * Close a block (decrease indent + write closing line).
     */
    public function closeBlock(string $line = '}'): self
    {
        $this->indentLevel--;
        $this->writeLine($line);
        return $this;
    }

    /**
     * Increase indentation level.
     */
    public function indent(): string
    {
        return str_repeat($this->indentString, $this->indentLevel);
    }

    /**
     * Push indent level.
     */
    public function pushIndent(): self
    {
        $this->indentLevel++;
        return $this;
    }

    /**
     * Pop indent level.
     */
    public function popIndent(): self
    {
        $this->indentLevel--;
        return $this;
    }

    /**
     * Write a PHPDoc comment block.
     *
     * Each input line is soft-wrapped at {@see DOC_WRAP_WIDTH} chars,
     * breaking on whitespace so no word crosses the boundary. Lines that
     * are already inside the budget pass through unchanged. Empty
     * strings and pre-wrapped short lines (paragraph breaks, `@param`
     * tags, etc.) are emitted verbatim so callers stay in control of
     * paragraph structure.
     *
     * @param string[] $lines
     */
    public function writeDocBlock(array $lines): self
    {
        $this->writeLine('/**');
        foreach ($lines as $line) {
            $line = \str_replace('*/', '* /', $line);
            foreach (self::wrapDocLine($line) as $wrapped) {
                $this->writeLine(
                    $wrapped === '' ? ' *' : ' * ' . $wrapped
                );
            }
        }
        $this->writeLine(' */');

        return $this;
    }

    /**
     * Render a supported value as a PHP source literal.
     */
    public static function exportLiteral(mixed $value): string
    {
        if ($value === null
            || \is_bool($value)
            || \is_int($value)
            || \is_float($value)
            || \is_string($value)
        ) {
            return \var_export($value, true);
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(
                'Cannot export unsupported PHP literal value'
            );
        }

        $parts = [];
        $isList = \array_is_list($value);
        foreach ($value as $key => $member) {
            $prefix = $isList
                ? ''
                : self::exportLiteral($key) . ' => ';
            $parts[] = $prefix . self::exportLiteral($member);
        }

        return '[' . \implode(', ', $parts) . ']';
    }

    /**
     * Write a generated class declaration.
     *
     * @param list<string> $interfaces
     */
    public function writeClassDeclaration(
        string $declaration,
        array $interfaces = []
    ): self
    {
        if ($interfaces === []) {
            return $this->writeLine($declaration);
        }

        $singleLine = $declaration
            . ' implements '
            . \implode(', ', $interfaces);
        if (\strlen($singleLine) <= self::MAX_GENERATED_LINE_WIDTH) {
            return $this->writeLine($singleLine);
        }

        $this->writeLine($declaration . ' implements');
        $this->pushIndent();
        $lastIndex = \count($interfaces) - 1;
        foreach ($interfaces as $index => $interface) {
            $suffix = $index === $lastIndex ? '' : ',';
            $this->writeLine($interface . $suffix);
        }
        $this->popIndent();

        return $this;
    }

    /**
     * Word-wrap a docblock line at `$width` chars, breaking on
     * whitespace. Returns the input as a single-element list when it
     * already fits or contains no whitespace break point.
     *
     * The break scan uses `strrpos` on a `$width + 1` prefix so the
     * boundary is the last space that keeps the emitted line at or
     * below the budget. Falls back to a hard split at the boundary
     * only when the prefix has no whitespace at all - rare enough
     * (`URL`s, single tokens) that a mid-word break is acceptable.
     *
     * @return list<string>
     */
    public static function wrapDocLine(string $text, int $width = self::DOC_WRAP_WIDTH): array
    {
        if (\strlen($text) <= $width) {
            return [$text];
        }

        $lines = [];
        $remaining = $text;
        while (\strlen($remaining) > $width) {
            $breakAt = \strrpos(\substr($remaining, 0, $width + 1), ' ');
            if ($breakAt === false || $breakAt === 0) {
                $breakAt = $width;
            }
            $lines[] = \substr($remaining, 0, $breakAt);
            $remaining = \ltrim(\substr($remaining, $breakAt));
        }
        if ($remaining !== '') {
            $lines[] = $remaining;
        }
        return $lines;
    }

    /**
     * Generate the complete file content including header, namespace, imports.
     */
    public function toFileContent(): string
    {
        $output = "<?php\n\n";
        $output .= "declare(strict_types=1);\n\n";

        if ($this->namespace !== '') {
            $output .= "namespace {$this->namespace};\n\n";
        }

        if (!empty($this->imports)) {
            ksort($this->imports);
            foreach ($this->imports as $fqcn => $shortName) {
                $shortNameFromFqcn = $this->extractShortName($fqcn);
                if ($shortName !== $shortNameFromFqcn) {
                    $output .= "use {$fqcn} as {$shortName};\n";
                } else {
                    $output .= "use {$fqcn};\n";
                }
            }
            $output .= "\n";
        }

        $output .= $this->buffer;

        return self::removeTrailingCommas($output);
    }

    /**
     * Remove trailing commas from generated arrays and argument lists.
     */
    private static function removeTrailingCommas(string $source): string
    {
        $tokens = \token_get_all($source);
        $output = '';
        $tokenCount = \count($tokens);

        foreach ($tokens as $index => $token) {
            if ($token !== ',') {
                $output .= \is_array($token) ? $token[1] : $token;

                continue;
            }

            $nextIndex = $index + 1;
            while ($nextIndex < $tokenCount) {
                $next = $tokens[$nextIndex];
                if (\is_array($next)
                    && \in_array(
                        $next[0],
                        [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                        true
                    )
                ) {
                    ++$nextIndex;

                    continue;
                }

                break;
            }

            $next = $tokens[$nextIndex] ?? null;
            if ($next !== ']' && $next !== ')') {
                $output .= ',';
            }
        }

        return $output;
    }

    /**
     * Get just the body buffer (no header/imports).
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Reset the writer state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->indentLevel = 0;
        $this->imports = [];
    }

    private function extractNamespace(string $fqcn): string
    {
        $lastSlash = strrpos($fqcn, '\\');
        if ($lastSlash === false) {
            return '';
        }
        return substr($fqcn, 0, $lastSlash);
    }

    private function extractShortName(string $fqcn): string
    {
        $lastSlash = strrpos($fqcn, '\\');
        if ($lastSlash === false) {
            return $fqcn;
        }
        return substr($fqcn, $lastSlash + 1);
    }
}
