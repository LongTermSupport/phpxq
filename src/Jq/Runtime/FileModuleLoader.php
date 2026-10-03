<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LogicException;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Json\JsonDecoderInterface;

/**
 * Filesystem {@see ModuleLoaderInterface}. OWNER: evaluator-core worker (Task 2.11). Skeleton only.
 *
 * @api
 */
final readonly class FileModuleLoader implements ModuleLoaderInterface
{
    /**
     * @param list<string> $libraryPaths the -L directories, in order
     */
    public function __construct(
        private array $libraryPaths,
        private ParserInterface $parser,
        private JsonDecoderInterface $decoder,
    ) {
    }

    public function loadLibrary(string $relativePath, mixed $searchMetadata, ?string $importerPath): LoadedModule
    {
        throw new LogicException(\sprintf('Module loading is not implemented (%d library paths, %s)', \count($this->libraryPaths), $this->parser::class));
    }

    public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array
    {
        throw new LogicException('Module data loading is not implemented (' . $this->decoder::class . ')');
    }
}
