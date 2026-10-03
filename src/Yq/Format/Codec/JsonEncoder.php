<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Writes JSON the way the reference does: `-I n` spaces of indentation (compact at 0), aliases and merge
 * keys resolved, comments dropped, number text kept, no HTML escaping. A top-level scalar prints bare when
 * `unwrapScalar` is on.
 */
final class JsonEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    /** @var array<string, string> */
    private const array ESCAPES = [
        '"'        => '\\"',
        '\\'       => '\\\\',
        "\n"       => '\\n',
        "\r"       => '\\r',
        "\t"       => '\\t',
        "\x08"     => '\\b',
        "\x0c"     => '\\f',
        "\u{2028}" => '\\u2028',
        "\u{2029}" => '\\u2029',
    ];

    private const string NEEDS_ESCAPE = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\xe2\x80";

    private const string JSON_NUMBER = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?$/D';

    public function format(): Format
    {
        return Format::Json;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if ($options->unwrapScalar && NodeKind::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        $out = '';
        $this->write($out, $root, $options->indent > 0 ? str_repeat(' ', $options->indent) : '', 0);

        return $out . "\n";
    }

    private function write(string &$out, Node $node, string $indent, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('json: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKind::Scalar === $node->kind) {
            $out .= $this->scalar($node);

            return;
        }

        $pretty = '' !== $indent;
        if (NodeKind::Sequence === $node->kind) {
            if ([] === $node->content) {
                $out .= '[]';

                return;
            }

            $out .= '[';
            $first = true;
            foreach ($node->content as $item) {
                $out .= $first ? '' : ',';
                $first = false;
                if ($pretty) {
                    $out .= "\n" . str_repeat($indent, $depth + 1);
                }

                $this->write($out, $item, $indent, $depth + 1);
            }

            $out .= ($pretty ? "\n" . str_repeat($indent, $depth) : '') . ']';

            return;
        }

        $pairs = NodeTools::pairs($node);
        if ([] === $pairs) {
            $out .= '{}';

            return;
        }

        $out .= '{';
        $first = true;
        foreach ($pairs as [$key, $value]) {
            $out .= $first ? '' : ',';
            $first = false;
            if ($pretty) {
                $out .= "\n" . str_repeat($indent, $depth + 1);
            }

            $out .= $this->string(NodeTools::keyText($key)) . ($pretty ? ': ' : ':');
            $this->write($out, $value, $indent, $depth + 1);
        }

        $out .= ($pretty ? "\n" . str_repeat($indent, $depth) : '') . '}';
    }

    private function scalar(Node $node): string
    {
        switch ($node->tag) {
            case CoreSchema::TAG_NULL:
                return 'null';
            case CoreSchema::TAG_BOOL:
                return 'true' === strtolower($node->value) ? 'true' : 'false';
            case CoreSchema::TAG_INT:
                return NodeTools::integerText($node->value) ?? $this->string($node->value);
            case CoreSchema::TAG_FLOAT:
                return $this->float($node->value);
            default:
                return $this->string($node->value);
        }
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
            $frac  = $m[3] ?? '';
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
        $escaped = preg_replace_callback('/[\x00-\x1f]/', static fn (array $m): string => \sprintf('\\u%04x', \ord($m[0])), $escaped) ?? $escaped;

        return '"' . $escaped . '"';
    }

    private function scrub(string $text): string
    {
        $pattern = '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s';

        return preg_replace($pattern, "\u{FFFD}", $text) ?? $text;
    }
}
