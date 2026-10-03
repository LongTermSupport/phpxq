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
     * A separator goes between results whose file or document index differs, as in the reference.
     *
     * @param list<Candidate> $results
     *
     * @throws CliException
     */
    public function print(array $results): void
    {
        foreach ($results as $result) {
            $this->printOne($result, $result->fileIndex . ':' . $result->documentIndex);
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
        if (!$this->isNullOrFalse($node)) {
            $this->printedAnything = true;
        }
    }

    private function renderYaml(Node $node, bool $separator): string
    {
        $text     = '';
        $document = NodeKind::Document === $node->kind;
        $header   = $document ? $this->registry->headerFor($node) : '';

        if ($separator && !$this->emitOptions->noDocSeparator && !str_starts_with($header, HeaderSplitter::SEPARATOR_MARKER)) {
            $text .= "---\n";
        }

        if ($document) {
            // Document framing is the printer's business (separators, header), not the document's: the
            // reference does not keep `---`, `...` or directives on a node either.
            $node->explicitStart = false;
            $node->explicitEnd   = false;
            $node->directives    = '';
        }

        if ('' !== $header) {
            $text .= $this->renderHeader($header);
        }

        if ($document && $this->registry->isUntouchedEmpty($node)) {
            return $text;
        }

        return $text . $this->emitter->emit($node, $this->emitOptions);
    }

    private function renderHeader(string $header): string
    {
        $out = '';
        foreach (explode("\n", substr($header, 0, -1)) as $line) {
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
            return $this->formats->encoder($this->format)->encode($node->root(), $this->formatOptions, $this->index);
        } catch (FormatException $formatException) {
            throw new CliException($formatException->getMessage(), 0, $formatException);
        }
    }

    private function guardNul(Node $node): void
    {
        $root = $node->root();
        if ($this->nulSeparated && $this->emitOptions->unwrapScalar && NodeKind::Scalar === $root->kind && str_contains($root->value, "\0")) {
            throw new CliException("Can't serialize value because it contains NUL char and you are using NUL separated output");
        }
    }

    private function isNullOrFalse(Node $node): bool
    {
        $root = $node->root();

        return NodeKind::Scalar === $root->kind && ('!!null' === $root->tag || ('!!bool' === $root->tag && 'false' === $root->value));
    }
}
