<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * A module loader for programs run from PHP code that must not touch the file system: every `import` and
 * `include` is a compile error.
 *
 * @internal
 */
final readonly class RefusingModuleLoader implements ModuleLoaderInterface
{
    private const string MESSAGE = 'modules are disabled: import and include are not available in this mode';

    public function loadLibrary(string $relativePath, mixed $searchMetadata, ?string $importerPath): LoadedModule
    {
        throw new JqCompileException(self::MESSAGE);
    }

    public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array
    {
        throw new JqCompileException(self::MESSAGE);
    }
}
