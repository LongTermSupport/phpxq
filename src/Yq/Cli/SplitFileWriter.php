<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\RuntimeServices;

/**
 * Opens the output file for each result of `--split-exp`: the file name is the first value of the name
 * expression evaluated against the result (with `$index` bound to the result counter), given the output
 * format's extension when it has none, with its directories created.
 *
 * @internal
 */
final class SplitFileWriter
{
    /** A name PHP would hand to a stream wrapper: `scheme://...`, or `data:` which needs no slashes. */
    private const string STREAM_WRAPPER = '~^(?:[a-z][a-z0-9+.\-]*://|data:)~i';

    /** The error for a refused name: the name, then why. */
    private const string REFUSED = 'invalid split file name %s: %s';

    /** Why a name that climbs out of the current directory is refused. */
    private const string OUTSIDE = 'it must stay inside the current directory';

    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        private readonly ExpressionNodeInterface $nameExpression,
        private readonly EvaluatorInterface $evaluator,
        private readonly RuntimeServices $services,
        private readonly FormatEnum $format,
    ) {
    }

    /**
     * @return resource
     *
     * @throws CliException
     */
    public function open(Candidate $result, int $index): mixed
    {
        $this->close();

        $context = new EvaluationContext([$result], $this->services, ['index' => [new Candidate(Node::scalar((string)$index, '!!int'))]]);
        $names   = $this->evaluator->evaluate($this->nameExpression, $context);
        $name    = [] === $names ? '' : $names[0]->node->value;
        if (1 !== preg_match('/\.[a-zA-Z0-9]+$/', $name)) {
            $name .= '.' . $this->extension($this->format);
        }

        $name      = $this->confined($name);
        $directory = \dirname($name);
        if ('.' !== $directory && !is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CliException(\sprintf('mkdir %s: permission denied', $directory));
        }

        $handle = fopen($name, 'wb');
        if (false === $handle) {
            throw new CliException(\sprintf('open %s: permission denied', $name));
        }

        $this->handle = $handle;

        return $handle;
    }

    public function close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * The file name taken from the data, as an absolute plain path inside the current directory with `.` and `..`
     * resolved. A stream wrapper (`php://filter/...`, `file:///...`, `data:...`) would reach past the file system or
     * let the data pick a filter, and a path that climbs out of the directory, lexically or through a symlink that
     * already exists, would write anywhere the user can.
     *
     * @throws CliException when the name is a stream wrapper or leaves the current directory
     */
    private function confined(string $name): string
    {
        $this->refuseWrapper($name, $name);
        $root = getcwd();
        if (false === $root) {
            throw new CliException(\sprintf(self::REFUSED, $name, 'the current directory cannot be read'));
        }

        $segments = [];
        foreach (explode('/', str_starts_with($name, '/') ? $name : $root . '/' . $name) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' !== $segment) {
                $segments[] = $segment;
            } elseif (null === array_pop($segments)) {
                throw new CliException(\sprintf(self::REFUSED, $name, self::OUTSIDE));
            }
        }

        $path     = '/' . implode('/', $segments);
        $realRoot = realpath($root);
        $this->refuseWrapper($name, $path);
        if (!$this->isInside($path, $root) || !$this->isInside($this->resolvedPath($path), false === $realRoot ? $root : $realRoot)) {
            throw new CliException(\sprintf(self::REFUSED, $name, self::OUTSIDE));
        }

        return $path;
    }

    /**
     * @throws CliException when the path is a NUL-bearing name or one PHP would hand to a stream wrapper
     */
    private function refuseWrapper(string $name, string $path): void
    {
        if (str_contains($path, "\0") || 1 === preg_match(self::STREAM_WRAPPER, $path)) {
            throw new CliException(\sprintf(self::REFUSED, $name, 'stream wrappers are not allowed'));
        }
    }

    private function isInside(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/') . '/');
    }

    /**
     * Where the path really leads: its deepest part that exists (the path itself when it does, a dangling symlink
     * included), with every symlink resolved, then the rest; '' when an existing part cannot be resolved.
     */
    private function resolvedPath(string $path): string
    {
        $existing = $path;
        $rest     = '';
        while ('/' !== $existing && !file_exists($existing) && !is_link($existing)) {
            $rest     = '/' . basename($existing) . $rest;
            $existing = \dirname($existing);
        }

        $real = realpath($existing);

        return false === $real ? '' : rtrim($real, '/') . $rest;
    }

    private function extension(FormatEnum $format): string
    {
        return match ($format) {
            FormatEnum::Yaml  => 'yml',
            FormatEnum::Props => 'properties',
            FormatEnum::Shell => 'sh',
            default           => $format->value,
        };
    }
}
