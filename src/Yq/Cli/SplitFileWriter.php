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
     * The file name taken from the data, as a plain path inside the current directory with `.` and `..` resolved.
     * A stream wrapper (`php://filter/...`, `file:///...`, `data:...`) would reach past the file system or let the
     * data pick a filter, and a path that climbs out of the directory would write anywhere the user can.
     *
     * @throws CliException when the name is a stream wrapper or leaves the current directory
     */
    private function confined(string $name): string
    {
        if (str_contains($name, "\0") || 1 === preg_match(self::STREAM_WRAPPER, $name)) {
            throw new CliException(\sprintf(self::REFUSED, $name, 'stream wrappers are not allowed'));
        }

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

        $path   = '/' . implode('/', $segments);
        $inside = rtrim($root, '/') . '/';
        if (!str_starts_with($path, $inside)) {
            throw new CliException(\sprintf(self::REFUSED, $name, self::OUTSIDE));
        }

        return substr($path, \strlen($inside));
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
