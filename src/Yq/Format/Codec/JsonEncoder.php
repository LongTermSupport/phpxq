<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Writes JSON the way the reference does: `-I n` spaces of indentation (compact at 0), aliases and merge
 * keys resolved, comments dropped, number text kept, no HTML escaping. A top-level string stays quoted.
 * Colour output uses the reference's palette: keys cyan, strings green, numbers and booleans magenta.
 */
final readonly class JsonEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    private const int COLOR_KEY = 36;

    private const int COLOR_STRING = 32;

    private const int COLOR_NUMBER = 95;

    /** @var array<string, string> */
    private const array ESCAPES = [
        '"'        => '\"',
        '\\'       => '\\\\',
        "\n"       => '\n',
        "\r"       => '\r',
        "\t"       => '\t',
        "\u{2028}" => '\u2028',
        "\u{2029}" => '\u2029',
    ];

    private const string NEEDS_ESCAPE = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\xe2\x80";

    private const string JSON_NUMBER = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?$/D';

    public function format(): FormatEnum
    {
        return FormatEnum::Json;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if ($options->unwrapScalar && NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        $out = '';
        $this->write($out, $root, $options->indent > 0 ? str_repeat(' ', $options->indent) : '', 0, $options->colors);

        return $out . "\n";
    }

    private function write(string &$out, Node $node, string $indent, int $depth, bool $colors): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('json: exceeded max depth (alias cycle?)');
        }

        if (NodeKindEnum::Alias === $node->kind || NodeKindEnum::Document === $node->kind) {
            $node = NodeTools::unwrap($node);
        }

        if (NodeKindEnum::Scalar === $node->kind) {
            $text = $this->scalar($node);
            $out .= $colors ? $this->paint($text, $this->valueColor($node->tag)) : $text;

            return;
        }

        $pretty = '' !== $indent;
        if (NodeKindEnum::Sequence === $node->kind) {
            if ([] === $node->content) {
                $out .= '[]';

                return;
            }

            $separator = $pretty ? ",\n" . str_repeat($indent, $depth + 1) : ',';
            $out      .= $pretty ? "[\n" . str_repeat($indent, $depth + 1) : '[';
            $first     = true;
            foreach ($node->content as $item) {
                $out .= $first ? '' : $separator;
                $first = false;
                $this->write($out, $item, $indent, $depth + 1, $colors);
            }

            $out .= ($pretty ? "\n" . str_repeat($indent, $depth) : '') . ']';

            return;
        }

        $content = NodeTools::flatContent($node);
        $count   = \count($content);
        if (0 === $count) {
            $out .= '{}';

            return;
        }

        $separator = $pretty ? ",\n" . str_repeat($indent, $depth + 1) : ',';
        $colon     = $pretty ? ': ' : ':';
        $out      .= $pretty ? "{\n" . str_repeat($indent, $depth + 1) : '{';
        for ($i = 0; $i < $count; $i += 2) {
            $out .= 0 === $i ? '' : $separator;
            $key  = $content[$i];
            $key  = NodeTools::unwrap($key);
            $text = $this->string(NodeKindEnum::Scalar === $key->kind ? $key->value : '');
            $out .= ($colors ? $this->paint($text, self::COLOR_KEY) : $text) . $colon;
            $this->write($out, $content[$i + 1], $indent, $depth + 1, $colors);
        }

        $out .= ($pretty ? "\n" . str_repeat($indent, $depth) : '') . '}';
    }

    private function scalar(Node $node): string
    {
        return match ($node->tag) {
            CoreSchema::TAG_NULL  => 'null',
            CoreSchema::TAG_BOOL  => 'true' === strtolower($node->value) ? 'true' : 'false',
            CoreSchema::TAG_INT   => $this->integer($node->value),
            CoreSchema::TAG_FLOAT => $this->float($node->value),
            default               => $this->string($node->value),
        };
    }

    private function valueColor(string $tag): ?int
    {
        return match ($tag) {
            CoreSchema::TAG_NULL                                             => null,
            CoreSchema::TAG_BOOL, CoreSchema::TAG_INT, CoreSchema::TAG_FLOAT => self::COLOR_NUMBER,
            default                                                          => self::COLOR_STRING,
        };
    }

    private function paint(string $text, ?int $color): string
    {
        return null === $color ? $text : "\x1b[" . $color . 'm' . $text . "\x1b[0m";
    }

    private function integer(string $text): string
    {
        if ('0' === $text || ('' !== $text && '0' !== $text[0] && \strlen($text) === strspn($text, '0123456789'))) {
            return $text;
        }

        return NodeTools::integerText($text) ?? $this->string($text);
    }

    private function float(string $text): string
    {
        if (1 === preg_match(self::JSON_NUMBER, $text)) {
            return $text;
        }

        if (1 === preg_match('/^[-+]?\.(?:inf|Inf|INF)$|^\.(?:nan|NaN|NAN)$/D', $text)) {
            throw new FormatException('json: unsupported value: ' . $text);
        }

        if (1 === preg_match('/^([-+]?)([0-9]*)(?:\.([0-9]*))?((?:[eE][-+]?[0-9]+)?)$/D', $text, $m)) {
            $whole = '' === $m[2] ? '0' : $m[2];
            $frac  = $m[3];
            $dot   = str_contains($text, '.') ? '.' . ('' === $frac ? '0' : $frac) : '';

            return ('-' === $m[1] ? '-' : '') . $whole . $dot . $m[4];
        }

        return $this->string($text);
    }

    private function string(string $text): string
    {
        if (\strlen($text) === strcspn($text, self::NEEDS_ESCAPE) && 1 === preg_match('//u', $text)) {
            return '"' . $text . '"';
        }

        if (1 !== preg_match('//u', $text)) {
            $text = $this->scrub($text);
        }

        $escaped = strtr($text, self::ESCAPES);
        $escaped = preg_replace_callback('/[\x00-\x1f]/', static fn (array $m): string => \sprintf('\u%04x', \ord($m[0])), $escaped) ?? $escaped;

        return '"' . $escaped . '"';
    }

    private function scrub(string $text): string
    {
        $pattern = '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s';

        return preg_replace($pattern, "\u{FFFD}", $text) ?? $text;
    }
}
