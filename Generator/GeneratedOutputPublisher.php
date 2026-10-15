<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

/**
 * Transactionally replaces a managed generated-output directory.
 *
 * @internal
 */
final class GeneratedOutputPublisher
{
    /**
     * @param (\Closure(string, string): bool)|null $moveDirectory
     * @param (\Closure(string, string): void)|null $directoryRemover
     */
    public function __construct(
        private readonly ?\Closure $moveDirectory = null,
        private readonly ?\Closure $directoryRemover = null
    ) {}

    /**
     * @param list<GeneratedFile> $files
     */
    public function publish(array $files, string $outputDir): void
    {
        $outputRoot = GeneratedFile::resolveOutputRoot($outputDir);
        $this->validateWorkingDirectory($outputRoot);
        $parent = \dirname($outputRoot);
        if ($parent === $outputRoot) {
            throw new \LogicException(
                'The filesystem root cannot be managed generated output.'
            );
        }

        $this->prepareParent($parent);
        $outputRoot = GeneratedFile::resolveOutputRoot($outputDir);
        $this->validateWorkingDirectory($outputRoot);
        $parent = \dirname($outputRoot);
        $this->validateManagedOutput($outputRoot);
        $outputMode = $this->outputMode($outputRoot);

        $relativeFiles = $this->indexFiles($files, $outputDir);
        $suffix = \bin2hex(\random_bytes(8));
        $name = \basename($outputRoot);
        $stagingDirectory = $parent
            . DIRECTORY_SEPARATOR
            . ".{$name}.smithy-staging-{$suffix}";
        $backupDirectory = $parent
            . DIRECTORY_SEPARATOR
            . ".{$name}.smithy-backup-{$suffix}";

        if (!\mkdir($stagingDirectory, 0700)) {
            throw new \RuntimeException(
                "Unable to create generated output staging directory: "
                    . $stagingDirectory
            );
        }

        try {
            $this->prepareStagingDirectory(
                $stagingDirectory,
                $outputRoot,
                $outputMode
            );

            foreach ($relativeFiles as $relativePath => $file) {
                (new GeneratedFile(
                    path: $stagingDirectory
                        . DIRECTORY_SEPARATOR
                        . $relativePath,
                    content: $file->content
                ))->writeToDisk($stagingDirectory);
            }

            if (!\chmod($stagingDirectory, $outputMode)) {
                throw new \RuntimeException(
                    'Unable to apply generated output permissions to '
                        . $stagingDirectory
                );
            }

            $this->replaceOutput(
                $stagingDirectory,
                $outputRoot,
                $backupDirectory,
                $parent
            );
        } finally {
            if (\file_exists($stagingDirectory)
                || \is_link($stagingDirectory)
            ) {
                $this->removeDirectory($stagingDirectory, $parent);
            }
        }
    }

    private function prepareParent(string $parent): void
    {
        if (\is_dir($parent)) {
            return;
        }

        if (\file_exists($parent) || \is_link($parent)) {
            throw new \LogicException(
                "Generated output parent is not a directory: {$parent}"
            );
        }

        if (!\mkdir($parent, 0755, true) && !\is_dir($parent)) {
            throw new \RuntimeException(
                "Unable to create generated output parent: {$parent}"
            );
        }
    }

    private function validateWorkingDirectory(string $outputRoot): void
    {
        $workingDirectory = \getcwd();
        if ($workingDirectory === false) {
            throw new \RuntimeException(
                'Unable to resolve the current working directory.'
            );
        }

        if (GeneratedFile::containsPath(
            $outputRoot,
            $workingDirectory
        )) {
            throw new \LogicException(
                'Generated output cannot contain the current working '
                    . 'directory.'
            );
        }
    }

    private function validateManagedOutput(string $outputRoot): void
    {
        if (!\file_exists($outputRoot) && !\is_link($outputRoot)) {
            return;
        }

        if (\is_link($outputRoot) || !\is_dir($outputRoot)) {
            throw new \LogicException(
                "Generated output is not a managed directory: {$outputRoot}"
            );
        }

        $entries = new \FilesystemIterator(
            $outputRoot,
            \FilesystemIterator::SKIP_DOTS
        );
        if (!$entries->valid()) {
            return;
        }

        $schemasPath = $outputRoot
            . DIRECTORY_SEPARATOR
            . 'Schemas.php';
        if (!\is_file($schemasPath)
            || \is_link($schemasPath)
            || !$this->isGeneratedSchemasFile($schemasPath)
        ) {
            throw new \LogicException(
                'Refusing to replace nonempty output without a generated '
                    . "Schemas.php registry: {$outputRoot}"
            );
        }
    }

    private function isGeneratedSchemasFile(string $path): bool
    {
        $content = \file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                "Unable to read generated schema registry: {$path}"
            );
        }

        return \str_contains(
            $content,
            SchemasGenerator::GENERATED_FILE_SIGNATURE
        )
            && ($this->hasImportOrNamespace(
                $content,
                'Smithy\\Core\\Serde\\Schema'
            ))
            && ($this->hasImportOrNamespace(
                $content,
                'Smithy\\Core\\Shape\\ShapeType'
            ))
            && \str_contains($content, 'final class Schemas');
    }

    private function hasImportOrNamespace(
        string $content,
        string $class
    ): bool
    {
        $separator = \strrpos($class, '\\');
        if ($separator === false) {
            return false;
        }

        $namespace = \substr($class, 0, $separator);

        return \str_contains($content, "use {$class};")
            || \str_contains($content, "namespace {$namespace};");
    }

    private function outputMode(string $outputRoot): int
    {
        if (!\is_dir($outputRoot)) {
            return 0755 & ~\umask();
        }

        $permissions = \fileperms($outputRoot);
        if ($permissions === false) {
            throw new \RuntimeException(
                "Unable to inspect generated output permissions: {$outputRoot}"
            );
        }

        return $permissions & 07777;
    }

    private function prepareStagingDirectory(
        string $stagingDirectory,
        string $outputRoot,
        int $outputMode
    ): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' && \is_dir($outputRoot)) {
            $outputMetadata = @\stat($outputRoot);
            $stagingMetadata = @\stat($stagingDirectory);
            if ($outputMetadata === false || $stagingMetadata === false) {
                throw new \RuntimeException(
                    'Unable to inspect generated output ownership.'
                );
            }

            $owner = $outputMetadata['uid'];
            if ($owner !== $stagingMetadata['uid']
                && !@\chown($stagingDirectory, $owner)
            ) {
                throw new \RuntimeException(
                    'Unable to preserve generated output owner.'
                );
            }

            $group = $outputMetadata['gid'];
            $stagingMetadata = @\stat($stagingDirectory);
            if ($stagingMetadata === false
                || ($group !== $stagingMetadata['gid']
                    && !@\chgrp($stagingDirectory, $group))
            ) {
                throw new \RuntimeException(
                    'Unable to preserve generated output group.'
                );
            }
        }

        $stagingMode = 0700 | ($outputMode & 02000);
        if (!\chmod($stagingDirectory, $stagingMode)) {
            throw new \RuntimeException(
                'Unable to restrict generated output staging directory: '
                    . $stagingDirectory
            );
        }
    }

    /**
     * @param list<GeneratedFile> $files
     * @return array<string, GeneratedFile>
     */
    private function indexFiles(array $files, string $outputDir): array
    {
        $indexed = [];
        foreach ($files as $file) {
            $relativePath = $file->pathRelativeTo($outputDir);
            $key = DIRECTORY_SEPARATOR === '\\'
                ? \strtolower($relativePath)
                : $relativePath;
            if (isset($indexed[$key])) {
                throw new \LogicException(
                    "Duplicate generated output path: {$relativePath}"
                );
            }

            $indexed[$key] = [
                'path' => $relativePath,
                'file' => $file
            ];
        }

        \ksort($indexed);

        $ordered = [];
        foreach ($indexed as $entry) {
            $ordered[$entry['path']] = $entry['file'];
        }

        return $ordered;
    }

    private function replaceOutput(
        string $stagingDirectory,
        string $outputDirectory,
        string $backupDirectory,
        string $parent
    ): void
    {
        if (\file_exists($backupDirectory)
            || \is_link($backupDirectory)
        ) {
            throw new \LogicException(
                "Generated output backup already exists: {$backupDirectory}"
            );
        }

        $hasSnapshot = \is_dir($outputDirectory);
        if ($hasSnapshot
            && !$this->move($outputDirectory, $backupDirectory)
        ) {
            throw new \RuntimeException(
                'Unable to back up existing generated output.'
            );
        }

        try {
            $published = $this->move(
                $stagingDirectory,
                $outputDirectory
            );
        } catch (\Throwable $failure) {
            $this->restoreSnapshot(
                $hasSnapshot,
                $backupDirectory,
                $outputDirectory,
                $failure
            );
        }

        if (!$published) {
            $this->restoreSnapshot(
                $hasSnapshot,
                $backupDirectory,
                $outputDirectory
            );
        }

        if ($hasSnapshot) {
            try {
                $this->removeDirectory($backupDirectory, $parent);
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'Generated output was published, but backup cleanup '
                        . "failed; remove the remaining backup at "
                        . $backupDirectory,
                    0,
                    $failure
                );
            }
        }
    }

    private function move(string $source, string $destination): bool
    {
        if ($this->moveDirectory !== null) {
            return ($this->moveDirectory)($source, $destination);
        }

        return \rename($source, $destination);
    }

    private function restoreSnapshot(
        bool $hasSnapshot,
        string $backupDirectory,
        string $outputDirectory,
        ?\Throwable $failure = null
    ): never
    {
        if ($hasSnapshot) {
            try {
                $restored = $this->move(
                    $backupDirectory,
                    $outputDirectory
                );
            } catch (\Throwable $restoreFailure) {
                throw new \RuntimeException(
                    'Unable to restore generated output; backup retained at '
                        . $backupDirectory,
                    0,
                    $failure ?? $restoreFailure
                );
            }

            if (!$restored) {
                throw new \RuntimeException(
                    'Unable to restore generated output; backup retained at '
                        . $backupDirectory,
                    0,
                    $failure
                );
            }
        }

        throw new \RuntimeException(
            $hasSnapshot
                ? 'Unable to replace generated output; snapshot restored.'
                : 'Unable to publish generated output.',
            0,
            $failure
        );
    }

    private function removeDirectory(
        string $directory,
        string $parent
    ): void
    {
        if ($this->directoryRemover !== null) {
            ($this->directoryRemover)($directory, $parent);

            return;
        }

        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }

        if (\is_link($directory) || !\is_dir($directory)) {
            throw new \LogicException(
                "Refusing to remove non-directory generated output: "
                    . $directory
            );
        }

        $resolvedDirectory = GeneratedFile::resolveOutputRoot($directory);
        $resolvedParent = GeneratedFile::resolveOutputRoot($parent);
        if (\dirname($resolvedDirectory) !== $resolvedParent) {
            throw new \LogicException(
                "Refusing to remove generated output outside {$parent}: "
                    . $directory
            );
        }

        $symlinkIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($symlinkIterator as $entry) {
            if ($entry->isLink()) {
                $this->removeSymlink($entry->getPathname());
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $this->removeSymlink($entry->getPathname());
            } elseif (!$entry->isDir()) {
                if (!\unlink($entry->getPathname())) {
                    throw new \RuntimeException(
                        'Unable to remove generated output file: '
                            . $entry->getPathname()
                    );
                }
            } elseif (!\rmdir($entry->getPathname())) {
                throw new \RuntimeException(
                    'Unable to remove generated output directory: '
                        . $entry->getPathname()
                );
            }
        }

        if (!\rmdir($directory)) {
            throw new \RuntimeException(
                "Unable to remove generated output directory: {$directory}"
            );
        }
    }

    private function removeSymlink(string $path): void
    {
        $removed = @\unlink($path);
        if (!$removed && DIRECTORY_SEPARATOR === '\\') {
            $removed = @\rmdir($path);
        }

        if (!$removed) {
            throw new \RuntimeException(
                "Unable to remove generated output symlink: {$path}"
            );
        }
    }
}
