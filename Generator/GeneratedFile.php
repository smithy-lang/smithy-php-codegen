<?php

declare(strict_types=1);

namespace Smithy\Codegen\Generator;

/**
 * Represents a generated PHP file with its content and target path.
 */
final class GeneratedFile
{
    /**
     * @internal
     */
    public function __construct(
        /** The file path where this file should be written */
        public readonly string $path,
        /** The full PHP file content */
        public readonly string $content
    ) {}

    /**
     * Write the generated file to disk.
     *
     * @return void
     * @throws \RuntimeException If the directory cannot be created or file cannot be written
     */
    public function writeToDisk(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    "Failed to create directory: {$dir}"
                );
            }
        }

        if (file_put_contents($this->path, $this->content) === false) {
            throw new \RuntimeException("Failed to write file: {$this->path}");
        }
    }
}
