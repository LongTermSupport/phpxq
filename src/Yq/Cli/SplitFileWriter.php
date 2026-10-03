<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\ExpressionNode;
use LTS\PhpXq\Yq\Format\Format;
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
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        private readonly ExpressionNode $nameExpression,
        private readonly EvaluatorInterface $evaluator,
        private readonly RuntimeServices $services,
        private readonly Format $format,
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
            $name .= '.' . self::extension($this->format);
        }

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

    private static function extension(Format $format): string
    {
        return match ($format) {
            Format::Yaml  => 'yml',
            Format::Props => 'properties',
            Format::Shell => 'sh',
            default       => $format->value,
        };
    }
}
