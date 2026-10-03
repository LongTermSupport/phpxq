<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * Finds and reads modules for `import` / `include` (jq's search rules: the directive's "search"
 * metadata, relative to the importing file, then the -L path, `~/.jq`, `$ORIGIN/../lib/jq`, ...).
 *
 * @api
 */
interface ModuleLoaderInterface
{
    /**
     * Load and parse the `.jq` library `$relativePath` (no extension).
     *
     * @param mixed   $searchMetadata the `search` member of the directive's metadata (string|list<string>|null)
     * @param ?string $importerPath   file the directive appears in, null for the main program
     *
     * @throws JqCompileException not found, cyclic import, or a syntax error inside the module
     */
    public function loadLibrary(string $relativePath, mixed $searchMetadata, ?string $importerPath): LoadedModule;

    /**
     * Load the `.json` data file `$relativePath` (no extension) and return all of its values in order.
     *
     * @return list<mixed>
     *
     * @throws JqCompileException
     */
    public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array;
}
