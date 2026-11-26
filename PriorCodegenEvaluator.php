<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen;

/**
 * Reads the previously-generated `Schemas.php` for a service and
 * extracts each shape's historical member ordering, so a subsequent
 * codegen run can preserve constructor slot layout (and therefore
 * ABI) even when the model adds new members.
 *
 * The `Schemas.php` output is codegen-owned - we control its shape -
 * so parsing it via {@see token_get_all()} is safe: any formatting
 * change we make to the emitter is caught in the same commit as this
 * evaluator gets updated. The alternative (a JSON sidecar) was
 * rejected in favor of using the generated schemas themselves as the
 * source of truth (the schema *is* the ordering).
 *
 * Failure mode is loud on purpose. If `Schemas.php` exists but the
 * parser can't recover a stable per-shape member list, we throw
 * rather than silently degrade to fresh ordering - silent degradation
 * would defeat the ABI-stability guarantee this class exists to
 * provide. Callers that want to opt out (a first-time generation, a
 * deliberate ABI-break release) do so via
 * {@see CodegenSettings::$preserveExistingMemberOrder}, not by making
 * the evaluator tolerant of a bad prior state.
 *
 * The extracted state is:
 *
 * ```
 * shapeId (fully-qualified)
 *   -> list<string>  // member names in memberIndex order
 * ```
 *
 * Only shapes with a `members: [...]` block are recorded. Enums,
 * lists, maps, and scalars don't have a member-ordering concern.
 */
final class PriorCodegenEvaluator
{
    /**
     * Filename of the schemas registry inside the output directory.
     * Matches whatever `SchemasGenerator` emits - kept as a constant
     * so tests and callers reference the same string.
     */
    public const SCHEMAS_FILENAME = 'Schemas.php';

    /**
     * @param array<string, list<string>> $memberOrders
     *                      shapeId -> historical member-name order. Sparse: a shape
     *                      absent from the map indicates "no prior generation
     *                      recorded ordering for this shape" (either new-in-model or
     *                      a shape type with no members).
     */
    private function __construct(
        private readonly array $memberOrders
    ) {}

    /**
     * Build an evaluator by inspecting the previously-generated
     * `Schemas.php` at the given output directory. Returns `null` if
     * no prior generation exists (natural first-run case - codegen
     * proceeds with fresh ordering, and the next run will find the
     * newly-emitted `Schemas.php` here).
     *
     * @throws \RuntimeException on I/O errors or when the file exists
     *                           but the token scanner can't recover per-shape member lists.
     *                           Both are hard-fail cases because silent fallback would break
     *                           ABI silently.
     */
    public static function inspect(string $outputDir): ?self
    {
        $path = \rtrim($outputDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . self::SCHEMAS_FILENAME;

        if (!\is_file($path)) {
            return null;
        }

        $source = \file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException(
                "Failed to read prior-codegen Schemas at {$path}"
            );
        }

        $orders = self::parseSchemasSource($source, $path);
        return new self($orders);
    }

    /**
     * Historical member order for a shape, or `null` when the shape
     * didn't exist in the prior generation. Callers thread the
     * return value into
     * {@see \Smithy\Codegen\Model\SmithyModel::getMembersOrdered()}
     * as its `$preservedOrder` argument.
     *
     * @return list<string>|null
     */
    public function memberOrderFor(string $shapeId): ?array
    {
        return $this->memberOrders[$shapeId] ?? null;
    }

    /**
     * Whether the prior generation recorded any ordering. When true,
     * `Schemas.php` was successfully parsed. when false, the file
     * either didn't exist or contained no shape blocks with member
     * lists. Callers can gate optional logging on this.
     */
    public function hasPriorOrdering(): bool
    {
        return $this->memberOrders !== [];
    }

    /**
     * Scan the token stream of a prior `Schemas.php` and return
     * `[shapeId => list<memberName>]` in `memberIndex` order.
     *
     * The `Schemas.php` shape (per `SchemasGenerator`) puts each
     * generated shape inside a `new Schema(...)` factory call. Every
     * such call begins with a `id: '...'` string literal and, when
     * the shape has members, a `members: [...]` array containing
     * nested `new Schema(...)` calls that carry `memberName: '...'`
     * and `memberIndex: N` named arguments.
     *
     * We walk the token stream sequentially, using a small state
     * machine:
     *
     *   - Track a stack of "currently inside `new Schema(`" contexts
     *     with each entry recording (shapeId, list-of-members).
     *   - When we see `id: '...'`, record it on the top of the stack
     *     as the shape ID (only if not already set - first `id`
     *     inside a `new Schema(` block is the shape ID. subsequent
     *     `id:` under nested `new Schema` for members are the
     *     member's target-shape ID, not the parent's).
     *   - When we see `memberName: '...'` and `memberIndex: N`
     *     inside a nested `new Schema(`, buffer them on the
     *     immediately-enclosing member entry.
     *   - On the closing `)` of the nested `new Schema(`, flush the
     *     buffered member onto the parent's member list.
     *   - On the closing `)` of the outer `new Schema(`, finalize
     *     the shape entry into the result map (sorted by
     *     `memberIndex`) and pop the stack.
     *
     * Comments and whitespace are elided by `token_get_all` when we
     * pass the right flags. We do a single linear pass. no
     * backtracking.
     *
     * @return array<string, list<string>>
     * @throws \RuntimeException when the token scanner encounters a
     *                           structural inconsistency (e.g. `memberIndex` without a
     *                           matching `memberName`). This is a hard fail because a
     *                           misparsed prior generation would silently reorder new
     *                           constructor slots.
     */
    private static function parseSchemasSource(string $source, string $path): array
    {
        // `TOKEN_PARSE` forces PHP 8 named-arg tokenization semantics
        // even when the parser host runs an older mode. we rely on
        // named-arg positions to distinguish `memberName` from other
        // string keys.
        //
        // `TOKEN_PARSE` also asks the lexer to perform structural
        // validation (unclosed parens, unterminated strings, etc.),
        // so genuinely-malformed source throws `\ParseError`. We
        // rethrow those as `\RuntimeException` so callers only need
        // to catch one exception type for "the prior generation
        // isn't usable" - silent fallback would break ABI, which is
        // exactly what this class exists to prevent.
        try {
            $tokens = \token_get_all($source, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException(
                "Prior Schemas.php at {$path} is malformed: {$e->getMessage()}",
                0,
                $e
            );
        }

        // Two-level state:
        //   $shapes: [shapeId => [memberIndex => memberName]]
        //     - result accumulator. populated when an outer
        //     `new Schema(` block closes.
        //   $stack: LIFO of active `new Schema(` blocks. Each entry:
        //     [
        //       'depth' => parens-depth at open,
        //       'shapeId' => string|null,
        //       'membersDepth' => int|null (parens-depth where the
        //           `members:` bracket opened. used to detect when
        //           we've left the members array),
        //       'members' => [memberIndex => memberName],
        //       'currentMember' => ['name' => ?string, 'index' => ?int],
        //     ]
        //   $parenDepth: running parenthesis depth so we can correlate
        //     opens and closes to the correct stack entry.
        $shapes = [];
        $stack = [];
        $parenDepth = 0;

        // Look-ahead helper: skip whitespace/comment tokens starting
        // at `$i` and return the next meaningful token or the raw
        // single-char string, plus its new index.
        $skipTrivia = static function (array $tokens, int $i): array
        {
            $n = \count($tokens);
            while ($i < $n) {
                $t = $tokens[$i];
                if (\is_array($t)
                    && ($t[0] === \T_WHITESPACE
                        || $t[0] === \T_COMMENT
                        || $t[0] === \T_DOC_COMMENT)
                ) {
                    $i++;
                    continue;
                }
                return [$t, $i];
            }
            return [null, $i];
        };

        $n = \count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];

            // Detect `new Schema(`. In the token stream this is
            // `T_NEW` -> whitespace -> `T_STRING("Schema")` ->
            // maybe whitespace -> `(`.
            if (\is_array($token) && $token[0] === \T_NEW) {
                [$after, $j] = $skipTrivia($tokens, $i + 1);
                if (\is_array($after)
                    && $after[0] === \T_STRING
                    && $after[1] === 'Schema'
                ) {
                    [$open, $k] = $skipTrivia($tokens, $j + 1);
                    if ($open === '(') {
                        // Push a fresh Schema block onto the stack.
                        $stack[] = [
                            'depth' => $parenDepth + 1,
                            'shapeId' => null,
                            'membersDepth' => null,
                            'members' => [],
                            'currentMember' => ['name' => null, 'index' => null]
                        ];
                        $parenDepth++;
                        $i = $k;
                        continue;
                    }
                }
            }

            // Named-argument sniffing. `T_STRING("id")` or `T_STRING(
            // "memberName")` or `T_STRING("memberIndex")` followed by
            // `:` (T_STRING or single-char) marks a named argument.
            // We only care about these three when the top-of-stack is
            // an in-flight Schema block.
            if (\is_array($token) && $token[0] === \T_STRING && $stack !== []) {
                $name = $token[1];
                if ($name === 'id'
                    || $name === 'memberName'
                    || $name === 'memberIndex'
                    || $name === 'members'
                ) {
                    [$colon, $j] = $skipTrivia($tokens, $i + 1);
                    if ($colon === ':') {
                        [$val, $k] = $skipTrivia($tokens, $j + 1);
                        // Handle the four kinds of named arg we care
                        // about. Values are string or integer literals
                        // (or in `members:`'s case, the opening `[`).
                        $topIdx = \count($stack) - 1;

                        if ($name === 'id') {
                            if (\is_array($val)
                                && $val[0] === \T_CONSTANT_ENCAPSED_STRING
                            ) {
                                // Only capture the first `id:` we see
                                // inside a given Schema block. nested
                                // Schema blocks (members) will each
                                // get their own top-of-stack entry.
                                if ($stack[$topIdx]['shapeId'] === null) {
                                    $stack[$topIdx]['shapeId']
                                        = self::unquotePhpString($val[1]);
                                }
                            }
                            $i = $k;
                            continue;
                        }

                        if ($name === 'members') {
                            // Record the parens-depth at which the
                            // `members: [` array opens. When we come
                            // back down to this depth via a `]`, we're
                            // done with members for this block.
                            if ($val === '[') {
                                $stack[$topIdx]['membersDepth'] = $parenDepth;
                            }
                            $i = $k;
                            continue;
                        }

                        if ($name === 'memberName') {
                            if (\is_array($val)
                                && $val[0] === \T_CONSTANT_ENCAPSED_STRING
                            ) {
                                $stack[$topIdx]['currentMember']['name']
                                    = self::unquotePhpString($val[1]);
                            }
                            $i = $k;
                            continue;
                        }

                        // Only `memberIndex` is left.
                        if (\is_array($val) && $val[0] === \T_LNUMBER) {
                            $stack[$topIdx]['currentMember']['index']
                                = (int) $val[1];
                        }
                        $i = $k;
                        continue;
                    }
                }
            }

            // Parenthesis depth tracking + Schema-block close detection.
            if ($token === '(') {
                $parenDepth++;
                continue;
            }
            if ($token === ')') {
                // If the top-of-stack entry opened at exactly this
                // parens-depth, we're closing that Schema block.
                if ($stack !== []) {
                    $topIdx = \count($stack) - 1;
                    if ($stack[$topIdx]['depth'] === $parenDepth) {
                        $closed = \array_pop($stack);
                        $parenDepth--;

                        // Nested (member-schema) block: flush its
                        // memberName/memberIndex up to the parent's
                        // members list. The parent is now on top.
                        if ($stack !== []) {
                            $parentIdx = \count($stack) - 1;
                            $mName = $closed['currentMember']['name'];
                            $mIdx = $closed['currentMember']['index'];
                            // Member entries without both name and
                            // index are ignored (e.g. `mapValue:` or
                            // `listMember:` nested schemas - not
                            // struct members of the parent).
                            if ($mName !== null && $mIdx !== null) {
                                $stack[$parentIdx]['members'][$mIdx] = $mName;
                            }
                        } else {
                            // Outer Schema block - commit to result.
                            if ($closed['shapeId'] !== null) {
                                if ($closed['members'] !== []) {
                                    \ksort($closed['members']);
                                    $shapes[$closed['shapeId']] = \array_values(
                                        $closed['members']
                                    );
                                } elseif (!isset($shapes[$closed['shapeId']])) {
                                    // Even memberless shapes deserve
                                    // an entry so we can distinguish
                                    // "shape existed before, had no
                                    // members" from "shape is new."
                                    // Empty list is the canonical
                                    // "no members" marker.
                                    $shapes[$closed['shapeId']] = [];
                                }
                            }
                        }
                        continue;
                    }
                }
                $parenDepth--;
                continue;
            }
        }

        // Sanity check: stack should have unwound to empty. Otherwise
        // we've misparsed something and callers would use the wrong
        // ordering - hard fail rather than degrade silently.
        if ($stack !== []) {
            throw new \RuntimeException(
                "Prior Schemas.php at {$path} has unbalanced parentheses "
                . "or malformed Schema block; token scanner left "
                . \count($stack) . ' entries on the stack.'
            );
        }

        return $shapes;
    }

    /**
     * Turn a T_CONSTANT_ENCAPSED_STRING literal (single- or
     * double-quoted) into its unescaped PHP string value. We only
     * strip the outer quote pair and undo the two escape sequences
     * that matter for our inputs - `\'` inside a single-quoted string
     * and `\\` in both. Member names and shape IDs never contain
     * backslashes or newlines, so a full PHP-quote unescape isn't
     * necessary.
     */
    private static function unquotePhpString(string $literal): string
    {
        $quote = $literal[0] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return $literal;
        }
        $inner = \substr($literal, 1, -1);
        if ($quote === "'") {
            // Single-quoted strings only interpret `\'` and `\\`.
            return \str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
        }
        // Double-quoted strings interpret more escapes, but we don't
        // emit them in `Schemas.php`. Handle the two that could show
        // up if formatting changes.
        return \str_replace(['\\"', "\\\\"], ['"', "\\"], $inner);
    }
}
