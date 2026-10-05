<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * A lenient pull-style XML reader that builds an {@see XmlElement} tree the way yq's decoder shapes it.
 *
 * Text is trimmed and kept in pieces; whitespace-only text is dropped; comments are filed as head, line or
 * foot comments depending on what the surrounding element had seen; processing instructions and directives
 * become children keyed with their prefix. Unknown entities are left as written unless strict mode is on.
 * `errorLine` is the line of an error found inside decoded text (0 when the reader's position says it all);
 * `textStart` is the source offset of the text being decoded.
 */
final class XmlReader
{
    public const string PROC_INST_PREFIX = '+p_';

    public const string DIRECTIVE_NAME = '+directive';

    private const string XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    private int $pos = 0;

    private int $errorLine = 0;

    private int $depth = 0;

    private int $textStart = 0;

    private readonly int $length;

    public function __construct(private readonly string $source, private readonly FormatOptions $options)
    {
        $this->length = \strlen($source);
        if (str_starts_with($source, "\u{FEFF}")) {
            $this->pos = 3;
        }
    }

    /**
     * @throws FormatException
     */
    public function read(): XmlElement
    {
        try {
            return $this->readTree();
        } catch (FormatException $formatException) {
            $message = $formatException->getMessage();
            $prefix  = 'XML syntax error: ';
            if (!str_starts_with($message, $prefix)) {
                throw $formatException;
            }

            $detail = substr($message, \strlen($prefix));
            $line   = $this->errorLine;
            if (0 === $line) {
                $offset = str_contains($detail, 'EOF') ? $this->length : $this->pos;
                $line   = 1 + substr_count($this->source, "\n", 0, $offset);
            }

            throw new FormatException(\sprintf('XML syntax error on line %d: %s', $line, $detail), 0, $formatException);
        }
    }

    /**
     * @throws FormatException
     */
    private function readTree(): XmlElement
    {
        $root = new XmlElement();
        $elem = $root;
        while ($this->pos < $this->length) {
            $lt = strpos($this->source, '<', $this->pos);
            if (false === $lt) {
                $this->text($elem, substr($this->source, $this->pos));
                $this->pos = $this->length;

                break;
            }

            if ($lt > $this->pos) {
                $this->text($elem, substr($this->source, $this->pos, $lt - $this->pos));
            }

            $this->pos = $lt;
            $elem      = $this->markup($elem);
        }

        if ($elem !== $root) {
            throw new FormatException('XML syntax error: unexpected EOF');
        }

        return $root;
    }

    private function markup(XmlElement $elem): XmlElement
    {
        $next = substr($this->source, $this->pos + 1, 1);
        if ('!' === $next) {
            if (str_starts_with(substr($this->source, $this->pos, 4), '<!--')) {
                $this->comment($elem);

                return $elem;
            }

            if (str_starts_with(substr($this->source, $this->pos, 9), '<![CDATA[')) {
                $this->cdata($elem);

                return $elem;
            }

            $this->directive($elem);

            return $elem;
        }

        if ('?' === $next) {
            $this->procInst($elem);

            return $elem;
        }

        if ('/' === $next) {
            return $this->endTag($elem);
        }

        return $this->startTag($elem);
    }

    private function comment(XmlElement $elem): void
    {
        $end = strpos($this->source, '-->', $this->pos + 4);
        if (false === $end) {
            throw new FormatException('XML syntax error: unexpected EOF in comment');
        }

        $text      = substr($this->source, $this->pos + 4, $end - $this->pos - 4);
        $this->pos = $end + 3;

        if (ElementStateEnum::Started === $elem->state) {
            $elem->headComment = NodeTools::joinComments($elem->headComment, $text);
        } elseif (ElementStateEnum::Chardata === $elem->state) {
            $elem->lineComment = '' === $elem->lineComment ? $text : $elem->lineComment . ' ' . $text;
        } elseif ($elem->lastChild instanceof XmlElement) {
            $elem->lastChild->footComment = NodeTools::joinComments($elem->lastChild->footComment, $text);
        }
    }

    private function cdata(XmlElement $elem): void
    {
        $end = strpos($this->source, ']]>', $this->pos + 9);
        if (false === $end) {
            throw new FormatException('XML syntax error: unexpected EOF in CDATA section');
        }

        $text      = substr($this->source, $this->pos + 9, $end - $this->pos - 9);
        $this->pos = $end + 3;
        $this->addText($elem, $this->normaliseNewlines($text));
    }

    private function procInst(XmlElement $elem): void
    {
        $end = strpos($this->source, '?>', $this->pos + 2);
        if (false === $end) {
            throw new FormatException('XML syntax error: unexpected EOF in processing instruction');
        }

        $body      = substr($this->source, $this->pos + 2, $end - $this->pos - 2);
        $this->pos = $end + 2;
        if ($this->options->xmlSkipProcInst) {
            return;
        }

        $targetLength = strcspn($body, " \t\r\n");
        $target       = substr($body, 0, $targetLength);
        if ('' === $target) {
            throw new FormatException('XML syntax error: expected target name after <?');
        }

        $instruction = ltrim(substr($body, $targetLength), " \t\r\n");
        $this->attach($elem, self::PROC_INST_PREFIX . $target, $instruction);
    }

    private function directive(XmlElement $elem): void
    {
        $i      = $this->pos + 2;
        $depth  = 0;
        $quote  = '';
        $text   = '';
        $length = $this->length;
        while (true) {
            if ($i >= $length) {
                throw new FormatException('XML syntax error: unexpected EOF in directive');
            }

            $char = $this->source[$i];
            if ('' === $quote && '>' === $char && 0 === $depth) {
                break;
            }

            if ('' !== $quote) {
                if ($char === $quote) {
                    $quote = '';
                }
            } elseif ('"' === $char || "'" === $char) {
                $quote = $char;
            } elseif ('>' === $char) {
                --$depth;
            } elseif ('<' === $char) {
                if ('<!--' === substr($this->source, $i, 4)) {
                    $end = strpos($this->source, '-->', $i + 4);
                    if (false === $end) {
                        throw new FormatException('XML syntax error: unexpected EOF in directive comment');
                    }

                    $text .= ' ';
                    $i = $end + 3;

                    continue;
                }

                ++$depth;
            }

            $text .= $char;
            ++$i;
        }

        $this->pos = $i + 1;
        if (!$this->options->xmlSkipDirectives) {
            $this->attach($elem, self::DIRECTIVE_NAME, $text);
        }
    }

    private function attach(XmlElement $elem, string $key, string $text): void
    {
        $child = XmlElement::leaf($key, $text);
        $elem->addChild($key, $child);
        $elem->state     = ElementStateEnum::Ended;
        $elem->lastChild = $child;
    }

    private function endTag(XmlElement $elem): XmlElement
    {
        $close = strpos($this->source, '>', $this->pos + 2);
        if (false === $close) {
            throw new FormatException('XML syntax error: unexpected EOF in end tag');
        }

        $name      = trim(substr($this->source, $this->pos + 2, $close - $this->pos - 2));
        $this->pos = $close + 1;
        if (!$elem->parent instanceof XmlElement) {
            throw new FormatException('XML syntax error: unexpected end element </' . $name . '>');
        }

        if ($elem->rawName !== $name) {
            throw new FormatException('XML syntax error: element <' . $elem->rawName . '> closed by </' . $name . '>');
        }

        $elem->parent->state     = ElementStateEnum::Ended;
        $elem->parent->lastChild = $elem;
        --$this->depth;

        return $elem->parent;
    }

    private function startTag(XmlElement $parent): XmlElement
    {
        $i = $this->pos + 1;
        $n = strcspn($this->source, " \t\r\n/>", $i);
        if (0 === $n) {
            throw new FormatException('XML syntax error: expected element name after <');
        }

        $rawName     = substr($this->source, $i, $n);
        $i          += $n;
        $attributes  = [];
        $selfClosing = false;
        while (true) {
            $i += strspn($this->source, " \t\r\n", $i);
            if ($i >= $this->length) {
                throw new FormatException('XML syntax error: unexpected EOF in start tag');
            }

            $char = $this->source[$i];
            if ('>' === $char) {
                ++$i;

                break;
            }

            if ('/' === $char) {
                if ('>' !== substr($this->source, $i + 1, 1)) {
                    throw new FormatException('XML syntax error: expected /> in element');
                }

                $i          += 2;
                $selfClosing = true;

                break;
            }

            [$attrName, $attrValue, $i] = $this->attribute($i);
            $attributes[]               = [$attrName, $attrValue];
        }

        $this->pos = $i;
        $elem      = new XmlElement($rawName, $parent);
        foreach ($attributes as [$attrName, $attrValue]) {
            if ('xmlns' === $attrName) {
                $elem->defaultNamespace = $attrValue;
            } elseif (str_starts_with($attrName, 'xmlns:')) {
                $elem->prefixes[substr($attrName, 6)] = $attrValue;
            }
        }

        $parent->addChild($this->elementKey($rawName, $elem), $elem);
        foreach ($attributes as [$attrName, $attrValue]) {
            $key = $this->options->xmlAttributePrefix . $this->attributeKey($attrName, $elem);
            $elem->addChild($key, XmlElement::leaf($key, $attrValue));
        }

        if ($selfClosing) {
            $parent->state     = ElementStateEnum::Ended;
            $parent->lastChild = $elem;

            return $parent;
        }

        if (Node::depthExceeded(++$this->depth)) {
            throw new FormatException('XML syntax error: ' . Node::depthError());
        }

        return $elem;
    }

    /**
     * @return array{string, string, int} name, decoded value, position after the attribute
     */
    private function attribute(int $i): array
    {
        $n = strcspn($this->source, " \t\r\n=/>", $i);
        if (0 === $n) {
            throw new FormatException('XML syntax error: expected attribute name in element');
        }

        $name = substr($this->source, $i, $n);
        $i   += $n;
        $i   += strspn($this->source, " \t\r\n", $i);
        if ('=' !== substr($this->source, $i, 1)) {
            if ($this->options->xmlStrictMode) {
                throw new FormatException('XML syntax error: attribute name without = in element');
            }

            return [$name, $name, $i];
        }

        ++$i;
        $i   += strspn($this->source, " \t\r\n", $i);
        $quote = substr($this->source, $i, 1);
        if ('"' === $quote || "'" === $quote) {
            $end = strpos($this->source, $quote, $i + 1);
            if (false === $end) {
                throw new FormatException('XML syntax error: unexpected EOF in attribute value');
            }

            return [$name, $this->decodeEntities(substr($this->source, $i + 1, $end - $i - 1)), $end + 1];
        }

        if ($this->options->xmlStrictMode) {
            throw new FormatException('XML syntax error: unquoted or missing attribute value in element');
        }

        $n = strcspn($this->source, " \t\r\n>", $i);
        if ('/' === substr($this->source, $i + $n - 1, 1) && '>' === substr($this->source, $i + $n, 1)) {
            --$n;
        }

        return [$name, $this->decodeEntities(substr($this->source, $i, $n)), $i + $n];
    }

    private function elementKey(string $rawName, XmlElement $elem): string
    {
        [$prefix, $local] = $this->splitName($rawName);
        if (!$this->options->xmlKeepNamespace) {
            return $local;
        }

        if ($this->options->xmlRawToken) {
            return $rawName;
        }

        $space = '' === $prefix ? $elem->defaultNamespace : ($elem->prefixes[$prefix] ?? ('xml' === $prefix ? self::XML_NAMESPACE : $prefix));

        return '' === $space ? $local : $space . ':' . $local;
    }

    private function attributeKey(string $rawName, XmlElement $elem): string
    {
        [$prefix, $local] = $this->splitName($rawName);
        if (!$this->options->xmlKeepNamespace) {
            return $local;
        }

        if ($this->options->xmlRawToken || '' === $prefix || 'xmlns' === $prefix) {
            return $rawName;
        }

        $space = $elem->prefixes[$prefix] ?? ('xml' === $prefix ? self::XML_NAMESPACE : $prefix);

        return $space . ':' . $local;
    }

    /**
     * @return array{string, string} prefix and local name
     */
    private function splitName(string $name): array
    {
        $colon = strpos($name, ':');
        if (false === $colon || 0 === $colon) {
            return ['', $name];
        }

        return [substr($name, 0, $colon), substr($name, $colon + 1)];
    }

    private function text(XmlElement $elem, string $raw): void
    {
        $this->textStart = $this->pos;
        $this->addText($elem, $this->decodeEntities($raw));
    }

    private function addText(XmlElement $elem, string $text): void
    {
        $text = trim($text, " \t\r\n\v\f");
        if ('' === $text) {
            return;
        }

        $elem->data[] = $text;
        $elem->state  = ElementStateEnum::Chardata;
    }

    private function normaliseNewlines(string $text): string
    {
        return str_contains($text, "\r") ? str_replace(["\r\n", "\r"], "\n", $text) : $text;
    }

    private function decodeEntities(string $text): string
    {
        $text = $this->normaliseNewlines($text);
        if (!str_contains($text, '&')) {
            return $text;
        }

        return preg_replace_callback(
            '/&(#x[0-9A-Fa-f]+|#[0-9]+|[A-Za-z_][A-Za-z0-9_.-]*);/',
            function (array $m) use ($text): string {
                $this->errorLine = 1 + substr_count($this->source, "\n", 0, $this->textStart) + substr_count($text, "\n", 0, (int)strpos($text, $m[0]));
                $entity          = $m[1];
                if ('#' === $entity[0]) {
                    $code = 'x' === substr($entity, 1, 1) ? (int)hexdec(substr($entity, 2)) : (int)substr($entity, 1);
                    if ($code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF)) {
                        return mb_chr($code, 'UTF-8');
                    }

                    if ($this->options->xmlStrictMode) {
                        throw new FormatException('XML syntax error: invalid character entity &' . $entity . ';');
                    }

                    return $m[0];
                }

                $known = match ($entity) {
                    'lt'    => '<',
                    'gt'    => '>',
                    'amp'   => '&',
                    'apos'  => "'",
                    'quot'  => '"',
                    default => null,
                };
                if (null !== $known) {
                    return $known;
                }

                if ($this->options->xmlStrictMode) {
                    throw new FormatException('XML syntax error: invalid character entity &' . $entity . ';');
                }

                return $m[0];
            },
            $text,
        ) ?? $text;
    }
}
