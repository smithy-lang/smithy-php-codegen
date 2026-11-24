<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

/**
 * Represents a generated PHP file with its content and target path.
 */
final class GeneratedFile
{
    /**
     * Matches URI-style schemes that PHP would interpret as stream wrappers.
     */
    private const STREAM_WRAPPER_PATH_PATTERN =
        '/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//D';

    /**
     * Matches a Windows drive designator at the beginning of a path.
     */
    private const WINDOWS_DRIVE_PREFIX_PATTERN = '/\A[A-Za-z]:/D';

    /**
     * Matches a Windows drive-relative path such as `C:generated`.
     */
    private const WINDOWS_DRIVE_RELATIVE_PATH_PATTERN =
        '/\A[A-Za-z]:(?!\\\\)/D';

    /**
     * Matches an absolute Windows drive path such as `C:\generated`.
     */
    private const WINDOWS_DRIVE_ABSOLUTE_PATH_PATTERN =
        '/\A[A-Za-z]:\\\\/';

    public function __construct(
        /** The file path where this file should be written */
        public readonly string $path,
        /** The full PHP file content */
        public readonly string $content
    ) {}

    /**
     * Resolve a configured output directory without requiring it to exist.
     *
     * @internal
     */
    public static function resolveOutputRoot(string $path): string
    {
        self::validatePathInput($path);
        $path = self::absolutePath($path, true);
        $leaf = \basename($path);
        if ($leaf === '..') {
            return self::resolveConfiguredRoot($path);
        }

        $parent = self::resolveConfiguredRoot(\dirname($path));

        return \rtrim($parent, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $leaf;
    }

    /**
     * Determine whether a root contains an existing path.
     *
     * @internal
     */
    public static function containsPath(string $root, string $path): bool
    {
        self::validatePathInput($root);
        self::validatePathInput($path);

        return self::isPathWithinRoot(
            self::resolveExistingAncestor(
                self::absolutePath($path, true)
            ),
            self::resolveOutputRoot($root)
        );
    }

    /**
     * Get this file's path relative to a configured output directory.
     *
     * @internal
     */
    public function pathRelativeTo(string $allowedRoot): string
    {
        self::validatePathInput($this->path);
        self::validatePathInput($allowedRoot);
        $root = self::absolutePath($allowedRoot, true);
        $path = self::absolutePath($this->path, true);
        $prefix = \rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;
        if (!self::pathStartsWith($path, $prefix)) {
            throw new \RuntimeException(
                "Refusing to map generated file outside {$root}: {$path}"
            );
        }

        $relativePath = \substr($path, \strlen($prefix));
        self::rejectParentTraversal($relativePath);

        return $relativePath;
    }

    /**
     * Write the generated file to disk.
     *
     * The caller must prevent concurrent mutation of the output tree during
     * this operation. Containment checks cannot prevent another process from
     * replacing a checked ancestor after validation.
     *
     * @return void
     * @throws \RuntimeException If the directory cannot be created or file cannot be written
     */
    public function writeToDisk(?string $allowedRoot = null): void
    {
        $path = $this->path;
        if ($allowedRoot !== null) {
            $path = self::validateDestination($path, $allowedRoot);
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    "Failed to create directory: {$dir}"
                );
            }
        }

        if ($allowedRoot !== null) {
            $path = self::validateDestination(
                $this->path,
                $allowedRoot
            );
        }

        if (\is_link($this->path) || \is_link($path)) {
            throw new \RuntimeException(
                "Refusing to overwrite symlink: {$path}"
            );
        }

        self::rejectHardLinkedDestination($path);

        if (file_put_contents($path, $this->content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * @return string Canonical destination path.
     */
    private static function validateDestination(
        string $path,
        string $allowedRoot
    ): string
    {
        self::validatePathInput($path);
        self::validatePathInput($allowedRoot);

        $path = self::absolutePath($path, true);
        $allowedRoot = self::absolutePath($allowedRoot, true);
        $inputPrefix = \rtrim($allowedRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;
        if (!self::pathStartsWith($path, $inputPrefix)) {
            throw new \RuntimeException(
                "Refusing to write generated file outside {$allowedRoot}: "
                    . $path
            );
        }

        $relativePath = \substr($path, \strlen($inputPrefix));
        self::rejectParentTraversal($relativePath);

        $resolvedRoot = self::resolveConfiguredRoot($allowedRoot);
        $destination = \rtrim($resolvedRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $relativePath;
        $resolvedPath = \rtrim(
            self::resolveExistingAncestor(\dirname($destination)),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . \basename($destination);

        if (!self::isPathWithinRoot($resolvedPath, $resolvedRoot)) {
            throw new \RuntimeException(
                "Refusing to write generated file outside {$allowedRoot}: "
                    . $path
            );
        }

        return $resolvedPath;
    }

    /**
     * @return void
     */
    private static function validatePathInput(string $path): void
    {
        if (\str_contains($path, "\0")) {
            throw new \RuntimeException(
                'Generated output paths must not contain NUL bytes.'
            );
        }

        if (\preg_match(self::STREAM_WRAPPER_PATH_PATTERN, $path) === 1) {
            throw new \RuntimeException(
                'Generated output paths must not use stream wrappers.'
            );
        }
    }

    private static function absolutePath(
        string $path,
        bool $allowParentTraversal = false
    ): string
    {
        $path = self::normalizeSeparators($path);
        if (self::isDriveRelativePath($path)) {
            $path = self::resolveDriveRelativePath($path);
        } elseif (!self::isAbsolutePath($path)) {
            $cwd = \getcwd();
            if ($cwd === false) {
                throw new \RuntimeException(
                    'Unable to resolve the current working directory.'
                );
            }

            $path = self::normalizeSeparators($cwd)
                . DIRECTORY_SEPARATOR
                . $path;
        }

        if (!$allowParentTraversal) {
            self::rejectParentTraversal($path);
        }

        $prefix = '';
        if (DIRECTORY_SEPARATOR === '\\') {
            if (\str_starts_with(
                $path,
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
            )) {
                $prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
                $path = \substr($path, 2);
            } elseif (\preg_match(
                self::WINDOWS_DRIVE_PREFIX_PATTERN,
                $path,
                $matches
            ) === 1) {
                $prefix = \strtoupper($matches[0]) . DIRECTORY_SEPARATOR;
                $path = \ltrim(
                    \substr($path, 2),
                    DIRECTORY_SEPARATOR
                );
            } elseif (\str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $prefix = DIRECTORY_SEPARATOR;
                $path = \substr($path, 1);
            }
        } elseif (\str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = \substr($path, 1);
        }

        $parts = [];
        foreach (\explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            $parts[] = $part;
        }

        return $prefix . \implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function normalizeSeparators(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return \str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return $path;
    }

    private static function isDriveRelativePath(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            && \preg_match(
                self::WINDOWS_DRIVE_RELATIVE_PATH_PATTERN,
                $path
            ) === 1;
    }

    private static function resolveDriveRelativePath(string $path): string
    {
        $drive = \strtoupper(\substr($path, 0, 2));
        $currentDirectory = \getcwd();
        if ($currentDirectory === false) {
            throw new \RuntimeException(
                'Unable to resolve the current working directory.'
            );
        }

        $currentDirectory = self::normalizeSeparators($currentDirectory);
        $currentDrive = \strtoupper(\substr($currentDirectory, 0, 2));
        $driveDirectory = $drive === $currentDrive
            ? $currentDirectory
            : \realpath($drive . '.');
        if ($driveDirectory === false) {
            throw new \RuntimeException(
                "Unable to resolve current directory for drive {$drive}"
            );
        }

        $suffix = \substr($path, 2);
        if ($suffix === '') {
            return self::normalizeSeparators($driveDirectory);
        }

        return \rtrim(
            self::normalizeSeparators($driveDirectory),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . $suffix;
    }

    /**
     * @return void
     */
    private static function rejectParentTraversal(string $path): void
    {
        foreach (\explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '..') {
                throw new \RuntimeException(
                    "Generated output path contains parent traversal: {$path}"
                );
            }
        }
    }

    /**
     * @return void
     */
    private static function rejectHardLinkedDestination(string $path): void
    {
        if (!\is_file($path)) {
            return;
        }

        $metadata = @\lstat($path);
        if ($metadata === false) {
            throw new \RuntimeException(
                "Unable to inspect generated output destination: {$path}"
            );
        }

        if (($metadata['nlink'] ?? 1) > 1) {
            throw new \RuntimeException(
                "Refusing to overwrite file with multiple hard links: {$path}"
            );
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return \str_starts_with($path, DIRECTORY_SEPARATOR)
                || \preg_match(
                    self::WINDOWS_DRIVE_ABSOLUTE_PATH_PATTERN,
                    $path
                ) === 1;
        }

        return \str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private static function resolveConfiguredRoot(string $path): string
    {
        [$root, $parts] = self::decomposeAbsolutePath($path);
        $current = $root;
        foreach ($parts as $part) {
            if ($part === '..') {
                if ($current === $root) {
                    continue;
                }

                $resolved = \realpath($current);
                if ($resolved !== false) {
                    $current = self::absolutePath($resolved);
                }

                $current = \dirname($current);
                continue;
            }

            $current = \rtrim($current, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . $part;
            $resolved = \realpath($current);
            if ($resolved !== false) {
                $current = self::absolutePath($resolved);
            }
        }

        return self::resolveExistingAncestor($current);
    }

    /**
     * @return array{string, list<string>}
     */
    private static function decomposeAbsolutePath(string $path): array
    {
        if (DIRECTORY_SEPARATOR === '\\'
            && \str_starts_with(
                $path,
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
            )
        ) {
            $parts = \explode(
                DIRECTORY_SEPARATOR,
                \substr($path, 2)
            );
            if (\count($parts) < 2
                || $parts[0] === ''
                || $parts[1] === ''
            ) {
                throw new \RuntimeException(
                    "Invalid UNC output path: {$path}"
                );
            }

            $root = DIRECTORY_SEPARATOR
                . DIRECTORY_SEPARATOR
                . \array_shift($parts)
                . DIRECTORY_SEPARATOR
                . \array_shift($parts);

            return [$root, $parts];
        }

        if (DIRECTORY_SEPARATOR === '\\'
            && \preg_match(
                self::WINDOWS_DRIVE_ABSOLUTE_PATH_PATTERN,
                $path
            ) === 1
        ) {
            return [
                \substr($path, 0, 3),
                \explode(DIRECTORY_SEPARATOR, \substr($path, 3))
            ];
        }

        return [
            DIRECTORY_SEPARATOR,
            \explode(DIRECTORY_SEPARATOR, \substr($path, 1))
        ];
    }

    private static function resolveExistingAncestor(string $path): string
    {
        $missing = [];
        $current = $path;
        while (!\file_exists($current) && !\is_link($current)) {
            $parent = \dirname($current);
            if ($parent === $current) {
                throw new \RuntimeException(
                    "Unable to resolve generated output path: {$path}"
                );
            }
            $missing[] = \basename($current);
            $current = $parent;
        }

        $resolved = \realpath($current);
        if ($resolved === false) {
            throw new \RuntimeException(
                "Unable to resolve generated output path: {$path}"
            );
        }

        while ($missing !== []) {
            $resolved .= DIRECTORY_SEPARATOR . \array_pop($missing);
        }

        return self::absolutePath($resolved);
    }

    private static function isPathWithinRoot(
        string $path,
        string $root
    ): bool
    {
        if (!\file_exists($root)) {
            return self::pathStartsWith(
                $path,
                \rtrim($root, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
            );
        }

        $rootPath = \realpath($root);
        $current = self::findExistingAncestor($path);
        if ($rootPath === false || $current === null) {
            return false;
        }

        $rootPath = self::absolutePath($rootPath);
        while (true) {
            if (self::isSameFilesystemEntry($current, $rootPath)) {
                return true;
            }

            $parent = \dirname($current);
            if ($parent === $current) {
                return false;
            }

            $resolvedParent = \realpath($parent);
            if ($resolvedParent === false) {
                return false;
            }
            $current = self::absolutePath($resolvedParent);
        }
    }

    private static function findExistingAncestor(string $path): ?string
    {
        $current = $path;
        while (!\file_exists($current) && !\is_link($current)) {
            $parent = \dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }

        $resolved = \realpath($current);

        return $resolved === false
            ? null
            : self::absolutePath($resolved);
    }

    private static function isSameFilesystemEntry(
        string $left,
        string $right
    ): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftMetadata = @\stat($left);
        $rightMetadata = @\stat($right);
        if ($leftMetadata === false || $rightMetadata === false) {
            return false;
        }

        $leftInode = $leftMetadata['ino'] ?? 0;
        $rightInode = $rightMetadata['ino'] ?? 0;
        if ($leftInode !== 0 || $rightInode !== 0) {
            return $leftMetadata['dev'] === $rightMetadata['dev']
                && $leftInode === $rightInode;
        }

        $leftPath = \realpath($left);
        $rightPath = \realpath($right);

        return $leftPath !== false
            && $rightPath !== false
            && $leftPath === $rightPath;
    }

    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return \str_starts_with($path, $prefix);
    }
}
