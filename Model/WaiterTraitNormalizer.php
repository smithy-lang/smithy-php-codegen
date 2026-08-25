<?php

declare(strict_types=1);

namespace Smithy\Codegen\Model;

/**
 * Normalize `smithy.waiters#waitable` into a codegen-ready structure.
 *
 * @internal
 */
final class WaiterTraitNormalizer
{
    private const TRAIT = 'smithy.waiters#waitable';
    private const DEFAULT_MIN_DELAY = 2;
    private const DEFAULT_MAX_DELAY = 120;
    private const STATES = ['success', 'failure', 'retry'];
    private const MATCHERS = [
        'output',
        'inputOutput',
        'success',
        'errorType'
    ];
    private const COMPARATORS = [
        'stringEquals',
        'booleanEquals',
        'allStringEquals',
        'anyStringEquals'
    ];

    private function __construct() {}

    /**
     * Normalize waiters attached to an operation.
     *
     * @return array<string, array<string, mixed>>
     * @throws \InvalidArgumentException If the shape is not an operation.
     * @throws \LogicException If the waitable trait is invalid.
     */
    public static function normalizeOperation(
        SmithyModel $model,
        string $operationShapeId
    ): array
    {
        $operation = $model->getShape($operationShapeId);
        if ($operation === null
            || ($operation['type'] ?? null) !== 'operation'
        ) {
            throw new \InvalidArgumentException(
                "Shape {$operationShapeId} is not an operation."
            );
        }

        $traits = $operation['traits'] ?? [];
        if (!\array_key_exists(self::TRAIT, $traits)) {
            return [];
        }

        $trait = $traits[self::TRAIT];
        if (!\is_array($trait)
            || ($trait !== [] && \array_is_list($trait))
        ) {
            self::invalid(
                $operationShapeId,
                null,
                'trait value must be a waiter map'
            );
        }

        self::validateEventStreams($model, $operationShapeId, $operation);

        $waiters = [];
        $waiterNames = [];
        foreach ($trait as $waiterName => $definition) {
            if (!\is_string($waiterName)
                || \preg_match('/^[A-Z][A-Za-z0-9]*$/D', $waiterName) !== 1
            ) {
                self::invalid(
                    $operationShapeId,
                    null,
                    'waiter names must use UpperCamelCase alphanumerics'
                );
            }

            $normalizedName = \strtolower($waiterName);
            if (isset($waiterNames[$normalizedName])) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'waiter name must be case-insensitively unique'
                );
            }
            $waiterNames[$normalizedName] = true;

            if (!\is_array($definition)) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'definition must be an object'
                );
            }

            $waiters[$waiterName] = self::normalizeWaiter(
                $operationShapeId,
                $waiterName,
                $definition,
                $operation
            );
        }

        return $waiters;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function normalizeWaiter(
        string $operationShapeId,
        string $waiterName,
        array $definition,
        array $operation
    ): array
    {
        $maxDelay = \array_key_exists('maxDelay', $definition)
            ? $definition['maxDelay']
            : self::DEFAULT_MAX_DELAY;
        if (!\is_int($maxDelay) || $maxDelay < 1) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'maxDelay must be an integer greater than or equal to 1'
            );
        }

        $minDelay = \array_key_exists('minDelay', $definition)
            ? $definition['minDelay']
            : self::DEFAULT_MIN_DELAY;
        if (!\is_int($minDelay)
            || $minDelay < 1
            || $minDelay > $maxDelay
        ) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'minDelay must be between 1 and maxDelay'
            );
        }

        $documentation = $definition['documentation'] ?? null;
        if (\array_key_exists('documentation', $definition)
            && !\is_string($documentation)
        ) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'documentation must be a string'
            );
        }

        $deprecated = \array_key_exists('deprecated', $definition)
            ? $definition['deprecated']
            : false;
        if (!\is_bool($deprecated)) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'deprecated must be a boolean'
            );
        }

        $tags = self::normalizeTags(
            $operationShapeId,
            $waiterName,
            \array_key_exists('tags', $definition)
                ? $definition['tags']
                : []
        );

        $acceptors = $definition['acceptors'] ?? null;
        if (!\is_array($acceptors)
            || !\array_is_list($acceptors)
            || $acceptors === []
        ) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'acceptors must be a non-empty list'
            );
        }

        $normalizedAcceptors = [];
        $hasSuccess = false;
        foreach ($acceptors as $index => $acceptor) {
            $normalized = self::normalizeAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                $acceptor,
                $operation
            );
            $normalizedAcceptors[] = $normalized;
            $hasSuccess = $hasSuccess
                || $normalized['state'] === 'success';
        }

        if (!$hasSuccess) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'acceptors must contain a success state'
            );
        }

        return [
            'documentation' => $documentation,
            'acceptors' => $normalizedAcceptors,
            'minDelay' => $minDelay,
            'maxDelay' => $maxDelay,
            'deprecated' => $deprecated,
            'tags' => $tags
        ];
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function normalizeAcceptor(
        string $operationShapeId,
        string $waiterName,
        int $index,
        mixed $acceptor,
        array $operation
    ): array
    {
        if (!\is_array($acceptor)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'must be an object'
            );
        }

        $state = $acceptor['state'] ?? null;
        if (!\is_string($state)
            || !\in_array($state, self::STATES, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'state must be success, failure, or retry'
            );
        }

        $matcher = self::normalizeMatcher(
            $operationShapeId,
            $waiterName,
            $index,
            $acceptor['matcher'] ?? null,
            $operation
        );

        $message = $acceptor['message'] ?? null;
        if (\array_key_exists('message', $acceptor)
            && (!\is_string($message) || $message === '')
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'message must be a non-empty string'
            );
        }

        if ($message !== null && $state !== 'failure') {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'message is only valid for a failure state'
            );
        }

        return [
            'state' => $state,
            'matcher' => $matcher,
            'message' => $message
        ];
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function normalizeMatcher(
        string $operationShapeId,
        string $waiterName,
        int $index,
        mixed $matcher,
        array $operation
    ): array
    {
        if (!\is_array($matcher) || \count($matcher) !== 1) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'matcher must set exactly one member'
            );
        }

        $type = \array_key_first($matcher);
        if (!\is_string($type)
            || !\in_array($type, self::MATCHERS, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'matcher type is not recognized'
            );
        }

        $value = $matcher[$type];
        if ($type === 'success') {
            if (!\is_bool($value)) {
                self::invalidAcceptor(
                    $operationShapeId,
                    $waiterName,
                    $index,
                    'success matcher must be a boolean'
                );
            }

            return [
                'type' => $type,
                'path' => null,
                'expected' => $value,
                'comparator' => null
            ];
        }

        if ($type === 'errorType') {
            if (!\is_string($value) || $value === '') {
                self::invalidAcceptor(
                    $operationShapeId,
                    $waiterName,
                    $index,
                    'errorType matcher must be a non-empty string'
                );
            }

            return [
                'type' => $type,
                'path' => null,
                'expected' => $value,
                'comparator' => null
            ];
        }

        self::validateOutputMatcherOperation(
            $operationShapeId,
            $waiterName,
            $index,
            $operation
        );

        return self::normalizePathMatcher(
            $operationShapeId,
            $waiterName,
            $index,
            $type,
            $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizePathMatcher(
        string $operationShapeId,
        string $waiterName,
        int $index,
        string $type,
        mixed $value
    ): array
    {
        if (!\is_array($value)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher must be an object"
            );
        }

        $path = $value['path'] ?? null;
        if (!\is_string($path) || $path === '') {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher path must be a non-empty string"
            );
        }

        $expected = $value['expected'] ?? null;
        if (!\is_string($expected)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher expected must be a string"
            );
        }

        $comparator = $value['comparator'] ?? null;
        if (!\is_string($comparator)
            || !\in_array($comparator, self::COMPARATORS, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher comparator is not recognized"
            );
        }

        if ($comparator === 'booleanEquals'
            && $expected !== 'true'
            && $expected !== 'false'
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'booleanEquals expected must be "true" or "false"'
            );
        }

        return [
            'type' => $type,
            'path' => $path,
            'expected' => $expected,
            'comparator' => $comparator
        ];
    }

    /**
     * @return list<string>
     */
    private static function normalizeTags(
        string $operationShapeId,
        string $waiterName,
        mixed $tags
    ): array
    {
        if (!\is_array($tags) || !\array_is_list($tags)) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'tags must be a list of strings'
            );
        }

        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'tags must be a list of strings'
                );
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return void
     */
    private static function validateOutputMatcherOperation(
        string $operationShapeId,
        string $waiterName,
        int $index,
        array $operation
    ): void
    {
        $outputShapeId = $operation['output']['target'] ?? null;
        if (!\is_string($outputShapeId)
            || $outputShapeId === 'smithy.api#Unit'
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'output matchers require an operation output'
            );
        }
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return void
     */
    private static function validateEventStreams(
        SmithyModel $model,
        string $operationShapeId,
        array $operation
    ): void
    {
        foreach (['input', 'output'] as $direction) {
            $structureId = $operation[$direction]['target'] ?? null;
            if (!\is_string($structureId)) {
                continue;
            }

            foreach ($model->getMembers($structureId) as $member) {
                $target = $member['target'] ?? null;
                if (!\is_string($target)
                    || $model->getShapeType($target) !== 'union'
                    || !$model->hasTrait(
                        $target,
                        'smithy.api#streaming'
                    )
                ) {
                    continue;
                }

                self::invalid(
                    $operationShapeId,
                    null,
                    'waiters cannot target an event-stream operation'
                );
            }
        }
    }

    private static function invalidAcceptor(
        string $operationShapeId,
        string $waiterName,
        int $index,
        string $message
    ): never
    {
        self::invalid(
            $operationShapeId,
            $waiterName,
            "acceptor {$index} {$message}"
        );
    }

    private static function invalid(
        string $operationShapeId,
        ?string $waiterName,
        string $message
    ): never
    {
        $subject = $waiterName === null
            ? "Operation {$operationShapeId} waitable trait"
            : "Operation {$operationShapeId} waiter {$waiterName}";

        throw new \LogicException("{$subject}: {$message}.");
    }
}
