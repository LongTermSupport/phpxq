<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Encoders and decoders: the `@format` operators (`@json`, `@yaml`, `@csv`, `@base64`, `@uri`, `@sh`, ...
 * and the decoding `@...d` forms) and `to_X` / `from_X` for the data formats. The data formats go through the
 * format registry; the string encodings are done here.
 */
final class FormatCalls implements CallOperatorInterface
{
    private const array DATA_FORMATS = ['json', 'yaml', 'props', 'xml', 'toml', 'hcl', 'lua', 'shell', 'kyaml', 'csv', 'tsv'];

    public function names(): array
    {
        $names = ['@sh', '@uri', '@urid', '@base64', '@base64d', '@base64url', '@base64urld', '@html', '@csv', '@tsv', '@csvd', '@tsvd'];
        foreach (self::DATA_FORMATS as $format) {
            $names[] = '@' . $format;
            $names[] = '@' . $format . 'd';
            $names[] = 'to_' . $format;
            $names[] = 'from_' . $format;
        }

        return array_values(array_unique($names));
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $out = [];
        foreach ($context->matches as $match) {
            $result = $this->one($call, $match, $context, $evaluator);
            if ($result instanceof Candidate) {
                $out[] = $result;
            }
        }

        return $out;
    }

    private function one(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): ?Candidate
    {
        $name = $call->name;
        $node = NodeOps::deref(Cands::node($match));
        if (str_starts_with($name, 'from_')) {
            return $this->decode(substr($name, 5), $node, $match, $context);
        }

        if (str_starts_with($name, 'to_')) {
            $indent = Args::int($call, 0, $context, $evaluator, $match);

            return $this->encode(substr($name, 3), $node, $match, $context, $indent ?? 2);
        }

        $bare = substr($name, 1);
        switch ($bare) {
            case 'sh':
                return Cands::derive(NodeOps::str(self::shell(self::text($node, $context))), $match);
            case 'uri':
                return Cands::derive(NodeOps::str(str_replace('%7E', '~', urlencode(self::text($node, $context)))), $match);
            case 'urid':
                return Cands::derive(NodeOps::str(urldecode(self::text($node, $context))), $match);
            case 'html':
                return Cands::derive(NodeOps::str(str_replace(["'", '"'], ['&#39;', '&#34;'], htmlspecialchars(self::text($node, $context), \ENT_NOQUOTES))), $match);
            case 'base64':
                return Cands::derive(NodeOps::str(base64_encode(self::text($node, $context))), $match);
            case 'base64d':
                return Cands::derive(NodeOps::str(self::base64Decode(self::text($node, $context))), $match);
            case 'base64url':
                return Cands::derive(NodeOps::str(strtr(base64_encode(self::text($node, $context)), '+/', '-_')), $match);
            case 'base64urld':
                return Cands::derive(NodeOps::str(self::base64Decode(strtr(self::text($node, $context), '-_', '+/'))), $match);
            default:
                break;
        }

        if (str_ends_with($bare, 'd') && \in_array(substr($bare, 0, -1), self::DATA_FORMATS, true)) {
            return $this->decode(substr($bare, 0, -1), $node, $match, $context);
        }

        return $this->encode($bare, $node, $match, $context, 'json' === $bare || 'xml' === $bare ? 0 : 2);
    }

    private function encode(string $formatName, Node $node, Candidate $match, EvaluationContext $context, int $indent): Candidate
    {
        if ('csv' === $formatName || 'tsv' === $formatName) {
            return Cands::derive(NodeOps::str(self::delimited($node, 'csv' === $formatName ? ',' : "\t", 'tsv' === $formatName)), $match);
        }

        $format = Format::fromName($formatName);
        if (!$format instanceof Format || !$format->canEncode()) {
            throw new EvaluationException(\sprintf('Unknown format %s', $formatName));
        }

        try {
            $text = $context->services->formats->encoder($format)->encode($node, new FormatOptions(indent: $indent), 0);
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }

        if (Format::Json === $format && 0 === $indent) {
            $text = rtrim($text, "\n");
        }

        return Cands::derive(NodeOps::str($text), $match);
    }

    private function decode(string $formatName, Node $node, Candidate $match, EvaluationContext $context): Candidate
    {
        $format = Format::fromName($formatName);
        if (!$format instanceof Format || !$format->canDecode()) {
            throw new EvaluationException(\sprintf('Unknown format %s', $formatName));
        }

        if (NodeKind::Scalar !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot decode a %s as %s', NodeOps::kindName($node), $formatName));
        }

        try {
            foreach ($context->services->formats->decoder($format)->decode($node->value, new FormatOptions()) as $document) {
                return Cands::derive(NodeOps::unwrap($document), $match);
            }
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }

        return Cands::derive(NodeOps::null(), $match);
    }

    private static function text(Node $node, EvaluationContext $context): string
    {
        if (NodeKind::Scalar === $node->kind) {
            return NodeOps::isNull($node) && 'null' === $node->value ? '' : $node->value;
        }

        try {
            return $context->services->formats->encoder(Format::Yaml)->encode($node, new FormatOptions(), 0);
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }
    }

    private static function base64Decode(string $text): string
    {
        $text    = rtrim($text, '=');
        $decoded = base64_decode($text, false);
        if (false === $decoded) {
            throw new EvaluationException('illegal base64 data');
        }

        return $decoded;
    }

    /**
     * Quotes runs of unsafe characters with single quotes and escapes embedded single quotes.
     */
    private static function shell(string $text): string
    {
        $pieces = explode("'", $text);
        $out    = [];
        foreach ($pieces as $piece) {
            if (1 !== preg_match('/[^A-Za-z0-9_@%+=:,.\/-]/', $piece)) {
                $out[] = $piece;

                continue;
            }

            preg_match('/^[A-Za-z0-9_@%+=:,.\/-]*/', $piece, $head);
            preg_match('/[A-Za-z0-9_@%+=:,.\/-]*$/', $piece, $tail);
            $prefix = $head[0];
            $suffix = $tail[0];
            $core   = substr($piece, \strlen($prefix), \strlen($piece) - \strlen($prefix) - \strlen($suffix));
            $out[]  = $prefix . "'" . $core . "'" . $suffix;
        }

        return implode("\\'", $out);
    }

    private static function delimited(Node $node, string $separator, bool $tabs): string
    {
        if (NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot encode %s as csv, it must be an array', NodeOps::kindName($node)));
        }

        $rows = [];
        $nested = false;
        foreach ($node->content as $item) {
            if (NodeKind::Sequence === NodeOps::deref($item)->kind) {
                $nested = true;
            }
        }

        $lists = $nested ? $node->content : [$node];
        foreach ($lists as $list) {
            $list  = NodeOps::deref($list);
            $cells = [];
            foreach ($list->content as $cell) {
                $cell = NodeOps::deref($cell);
                if (NodeKind::Scalar !== $cell->kind) {
                    throw new EvaluationException('Cannot encode a collection as a csv cell');
                }

                $text    = NodeOps::isNull($cell) ? '' : $cell->value;
                $cells[] = $tabs ? self::tsvCell($text) : self::csvCell($text, $separator);
            }

            $rows[] = implode($separator, $cells);
        }

        return implode("\n", $rows);
    }

    private static function csvCell(string $text, string $separator): string
    {
        if ('' === $text) {
            return '';
        }

        if ('\.' === $text || str_contains($text, $separator) || str_contains($text, '"') || str_contains($text, "\n") || str_contains($text, "\r") || 1 === preg_match('/^\s/u', $text)) {
            return '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    private static function tsvCell(string $text): string
    {
        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\t', '\n', '\r'], $text);
    }
}
