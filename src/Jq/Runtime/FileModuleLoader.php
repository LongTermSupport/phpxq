<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonSyntaxException;

/**
 * Filesystem {@see ModuleLoaderInterface} implementing jq's module search: the directive's `search` entries
 * (relative to the importing file), then the `-L` directories, or `~/.jq`, `$ORIGIN/../lib/jq` and
 * `$ORIGIN/../lib` when none were given. A module `foo` is `foo.jq` or `foo/foo.jq` inside a directory; a
 * data file is `foo.json` or `foo/foo.json`.
 *
 * @api
 */
final readonly class FileModuleLoader implements ModuleLoaderInterface
{
    private const array DEFAULT_PATHS = ['~/.jq', '$ORIGIN/../lib/jq', '$ORIGIN/../lib'];

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
        $path = $this->find($relativePath, $searchMetadata, $importerPath, '.jq');

        try {
            $program = $this->parser->parse($this->read($path));
        } catch (JqCompileException $exception) {
            throw new JqCompileException($exception->getMessage() . ' (in module ' . $path . ')', 0, $exception);
        }

        return new LoadedModule($program, $path);
    }

    public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array
    {
        $path = $this->find($relativePath, $searchMetadata, $importerPath, '.json');
        try {
            return iterator_to_array($this->decoder->decodeAll($this->read($path)), false);
        } catch (JsonSyntaxException $exception) {
            throw new JqCompileException($exception->getMessage() . ' (in data file ' . $path . ')', 0, $exception);
        }
    }

    /**
     * @throws JqCompileException
     */
    private function find(string $relativePath, mixed $searchMetadata, ?string $importerPath, string $suffix): string
    {
        self::validate($relativePath);
        $searched = [];
        foreach ($this->searchDirectories($searchMetadata, $importerPath) as $directory) {
            $base       = basename($relativePath);
            $candidates = [
                $directory . '/' . $relativePath . $suffix,
                $directory . '/' . $relativePath . '/' . $base . $suffix,
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $real = realpath($candidate);

                    return false === $real ? $candidate : $real;
                }
            }

            $searched[] = $directory;
        }

        throw new JqCompileException(\sprintf('module not found: %s (searched: %s)', $relativePath, implode(', ', $searched)));
    }

    /**
     * @return list<string>
     */
    private function searchDirectories(mixed $searchMetadata, ?string $importerPath): array
    {
        $origin      = null === $importerPath ? '.' : \dirname($importerPath);
        $directories = [];
        $entries     = [];
        if (\is_string($searchMetadata)) {
            $entries = [$searchMetadata];
        } elseif (\is_array($searchMetadata)) {
            $entries = $searchMetadata;
        }

        foreach ($entries as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            $expanded      = self::expand($entry);
            $directories[] = str_starts_with($expanded, '/') ? $expanded : $origin . '/' . $expanded;
        }

        foreach ([] === $this->libraryPaths ? self::DEFAULT_PATHS : $this->libraryPaths as $path) {
            $directories[] = self::expand($path);
        }

        return $directories;
    }

    private static function expand(string $path): string
    {
        if ('~' === $path || str_starts_with($path, '~/')) {
            $home = getenv('HOME');

            return (false === $home ? '' : $home) . substr($path, 1);
        }

        if (str_starts_with($path, '$ORIGIN/') || '$ORIGIN' === $path) {
            return \dirname(__DIR__, 3) . '/bin' . substr($path, 7);
        }

        return $path;
    }

    /**
     * @throws JqCompileException
     */
    private static function validate(string $relativePath): void
    {
        $previous = null;
        foreach (explode('/', $relativePath) as $component) {
            if ('..' === $component) {
                throw new JqCompileException(\sprintf('Relative paths to modules may not traverse to parent directories (%s)', $relativePath));
            }

            if (null !== $previous && $previous === $component && '' !== $component) {
                throw new JqCompileException(\sprintf('module names must not have equal consecutive components: %s', $relativePath));
            }

            $previous = $component;
        }
    }

    /**
     * @throws JqCompileException
     */
    private function read(string $path): string
    {
        $text = file_get_contents($path);
        if (false === $text) {
            throw new JqCompileException('Could not read ' . $path);
        }

        return $text;
    }
}
