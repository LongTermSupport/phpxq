<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

/**
 * An output buffer that places newlines and indentation exactly where Go's encoding/xml encoder does: a
 * newline and the indent before every start and end tag except the first tag written and the end tag of an
 * element that was just opened (so empty elements stay `<a></a>`); text, comments, processing instructions
 * and directives are written verbatim, never indented. An empty indent string turns layout off.
 *
 * @internal
 */
final class XmlWriter
{
    private string $out = '';

    private int $depth = 0;

    private bool $indentedIn = false;

    private bool $putNewline = false;

    public function __construct(private readonly string $indent)
    {
    }

    public function raw(string $text): void
    {
        $this->out .= $text;
    }

    public function start(string $name, string $attributes = ''): void
    {
        $this->layout(1);
        $this->out .= '<' . $name . $attributes . '>';
    }

    public function end(string $name): void
    {
        $this->layout(-1);
        $this->out .= '</' . $name . '>';
    }

    public function result(): string
    {
        return $this->out;
    }

    private function layout(int $depthDelta): void
    {
        if ('' === $this->indent) {
            return;
        }

        if ($depthDelta < 0) {
            --$this->depth;
            if ($this->indentedIn) {
                $this->indentedIn = false;

                return;
            }

            $this->indentedIn = false;
        }

        if ($this->putNewline) {
            $this->out .= "\n";
        } else {
            $this->putNewline = true;
        }

        $this->out .= str_repeat($this->indent, max(0, $this->depth));
        if ($depthDelta > 0) {
            ++$this->depth;
            $this->indentedIn = true;
        }
    }
}
