<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * CSV and TSV output. An array of arrays is one record per inner array, an array of scalars is a single
 * record, and an array of maps is a header (the keys of the first map, printed only for the first result)
 * followed by one record per map. Fields are quoted the way Go's csv writer quotes them.
 */
final readonly class CsvEncoder implements EncoderInterface
{
    public function __construct(private Format $format = Format::Csv)
    {
    }

    public function format(): Format
    {
        return $this->format;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if (NodeKind::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        if (NodeKind::Sequence !== $root->kind) {
            throw new FormatException('csv: only arrays can be written as ' . $this->format->value . ', got a map');
        }

        if ([] === $root->content) {
            return '';
        }

        $separator = Format::Tsv === $this->format ? $options->tsvSeparator : $options->csvSeparator;
        $separator = '' === $separator ? ',' : $separator;
        $first     = NodeTools::unwrap($root->content[0]);

        if (NodeKind::Scalar === $first->kind) {
            return $this->record($root->content, $separator);
        }

        if (NodeKind::Sequence === $first->kind) {
            $out = '';
            foreach ($root->content as $row) {
                $row = NodeTools::unwrap($row);
                if (NodeKind::Sequence !== $row->kind) {
                    throw new FormatException('csv: every row must be an array');
                }

                $out .= $this->record($row->content, $separator);
            }

            return $out;
        }

        return $this->objects($root, $separator, $resultIndex);
    }

    private function objects(Node $root, string $separator, int $resultIndex): string
    {
        $header = [];
        foreach (NodeTools::pairs(NodeTools::unwrap($root->content[0])) as [$key]) {
            $header[] = NodeTools::keyText($key);
        }

        $out = 0 === $resultIndex ? $this->fields($header, $separator) : '';
        foreach ($root->content as $item) {
            $item = NodeTools::unwrap($item);
            if (NodeKind::Mapping !== $item->kind) {
                throw new FormatException('csv: every row must be an object');
            }

            $values = [];
            foreach (NodeTools::pairs($item) as [$key, $value]) {
                $values[NodeTools::keyText($key)] = $value;
            }

            $cells = [];
            foreach ($header as $name) {
                $cells[] = isset($values[$name]) ? $this->cell($values[$name]) : '';
            }

            $out .= $this->fields($cells, $separator);
        }

        return $out;
    }

    /**
     * @param list<Node> $cells
     */
    private function record(array $cells, string $separator): string
    {
        $texts = [];
        foreach ($cells as $cell) {
            $texts[] = $this->cell($cell);
        }

        return $this->fields($texts, $separator);
    }

    private function cell(Node $node): string
    {
        $node = NodeTools::unwrap($node);
        if (NodeKind::Scalar !== $node->kind) {
            throw new FormatException('csv: cannot write a nested array or map into a field');
        }

        return $node->value;
    }

    /**
     * @param list<string> $fields
     */
    private function fields(array $fields, string $separator): string
    {
        $quoted = [];
        foreach ($fields as $field) {
            $quoted[] = $this->needsQuotes($field, $separator) ? '"' . str_replace('"', '""', $field) . '"' : $field;
        }

        return implode($separator, $quoted) . "\n";
    }

    private function needsQuotes(string $field, string $separator): bool
    {
        if ('' === $field) {
            return false;
        }

        if ('\\.' === $field || \strlen($field) !== strcspn($field, "\r\n\"") || str_contains($field, $separator)) {
            return true;
        }

        return 1 === preg_match('/^\s/u', $field);
    }
}
