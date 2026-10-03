<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;

/**
 * Writes evaluation results in the output format: document separators between documents, the slurped
 * header ahead of an unchanged document root, NUL separated output, per-result files for `--split-exp`,
 * and the bookkeeping `--exit-status` needs.
 *
 * YAML is written by the YAML emitter directly; every other format by its encoder.
 */
final class ResultPrinter
{
    private int $index = 0;

    private bool $printedAnything = false;

    private string $previousKey = '';

    /**
     * @param resource $out the main sink (standard output, or the in-place buffer)
     */
    public function __construct(
        private readonly mixed $out,
        private readonly Format $format,
        private readonly EmitOptions $emitOptions,
        private readonly FormatOptions $formatOptions,
        private readonly YamlEmitterInterface $emitter,
        private readonly FormatRegistryInterface $formats,
        private readonly DocumentRegistry $registry,
        private readonly bool $nulSeparated,
        private readonly ?SplitFileWriter $split = null,
    ) {
    }

    /**
     * @param list<Candidate> $results
     * @param ?string         $groupKey identifies the document these results came from; when null each
     *                                  result's own file and document index decide where a separator goes
     *
     * @throws CliException
     */
    public function print(array $results, ?string $groupKey = null): void
    {
        foreach ($results as $result) {
            $this->printOne($result, $groupKey ?? $result->fileIndex . ':' . $result->documentIndex);
        }
    }

    public function printedAnything(): bool
    {
        return $this->printedAnything;
    }

    /**
     * Writes trailing content (the non-YAML part of a front matter file) and closes split files.
     */
    public function finish(string $appendix): void
    {
        $this->split?->close();
        if ('' !== $appendix) {
            fwrite($this->out, $appendix);
        }
    }

    private function printOne(Candidate $result, string $key): void
    {
        $node = $result->node;
        $this->guardNul($node);

        $sink      = $this->split instanceof SplitFileWriter ? $this->split->open($result, $this->index) : $this->out;
        $separator = 0 !== $this->index && $key !== $this->previousKey;

        $text = Format::Yaml === $this->format
            ? $this->renderYaml($node, $separator)
            : $this->renderOther($node);

        if ($this->nulSeparated) {
            $text = (str_ends_with($text, "\n") ? substr($text, 0, -1) : $text) . "\0";
        }

        fwrite($sink, $text);

        $this->previousKey = $key;
        ++$this->index;
        if (!self::isNullOrFalse($node)) {
            $this->printedAnything = true;
        }
    }

    private function renderYaml(Node $node, bool $separator): string
    {
        $text = '';
        $document = $this->registry->documentFor($node);
        $header   = $this->registry->headerFor($node);
        $framed   = $document instanceof Node && ($document->explicitStart || $document->explicitEnd || '' !== $document->directives);
        $starts   = $framed && $document->explicitStart;

        if ($separator && !$this->emitOptions->noDocSeparator && !$starts && !str_starts_with($header, HeaderSplitter::SEPARATOR_MARKER)) {
            $text .= "---\n";
        }

        if ('' !== $header) {
            $text .= $this->renderHeader($header);
        }

        if ($this->registry->isUntouchedEmpty($node)) {
            return $text;
        }

        return $text . $this->emitter->emit($framed ? $document : $node, $this->emitOptions);
    }

    private function renderHeader(string $header): string
    {
        $out = '';
        foreach (explode("\n", rtrim($header, "\n")) as $line) {
            if (HeaderSplitter::SEPARATOR_MARKER === $line) {
                if (!$this->emitOptions->noDocSeparator) {
                    $out .= "---\n";
                }

                continue;
            }

            $out .= $line . "\n";
        }

        return $out;
    }

    private function renderOther(Node $node): string
    {
        try {
            return $this->formats->encoder($this->format)->encode($node, $this->formatOptions, $this->index);
        } catch (FormatException $e) {
            throw new CliException($e->getMessage(), 0, $e);
        }
    }

    private function guardNul(Node $node): void
    {
        if ($this->nulSeparated && $this->emitOptions->unwrapScalar && NodeKind::Scalar === $node->kind && str_contains($node->value, "\0")) {
            throw new CliException("Can't serialize value because it contains NUL char and you are using NUL separated output");
        }
    }

    private static function isNullOrFalse(Node $node): bool
    {
        return NodeKind::Scalar === $node->kind && ('!!null' === $node->tag || ('!!bool' === $node->tag && 'false' === $node->value));
    }
}
