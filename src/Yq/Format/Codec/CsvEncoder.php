<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * CSV and TSV output. An array of arrays is one record per inner array, an array of scalars is a single
 * record, and an array of maps is a header (the keys of the first map, printed only when `$resultIndex` is
 * zero) followed by one record per map. Fields are quoted the way Go's csv writer quotes them.
 *
 * @internal
 */
final readonly class CsvEncoder implements EncoderInterface
{
    public function __construct(private FormatEnum $format = FormatEnum::Csv)
    {
    }

    public function format(): FormatEnum
    {
        return $this->format;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        if (NodeKindEnum::Sequence !== $root->kind) {
            throw new FormatException('csv: only arrays can be written as ' . $this->format->value . ', got a map');
        }

        if ([] === $root->content) {
            return '';
        }

        $separator = FormatEnum::Tsv === $this->format ? $options->tsvSeparator : $options->csvSeparator;
        $separator = ''              === $separator ? ',' : $separator;

        $first     = NodeTools::unwrap($root->content[0]);

        if (NodeKindEnum::Scalar === $first->kind) {
            return $this->record($separator, ...$root->content);
        }

        if (NodeKindEnum::Sequence === $first->kind) {
            $out = '';
            foreach ($root->content as $row) {
                $row = NodeTools::unwrap($row);
                if (NodeKindEnum::Sequence !== $row->kind) {
                    throw new FormatException('csv: every row must be an array');
                }

                $out .= $this->record($separator, ...$row->content);
            }

            return $out;
        }

        return $this->objects($root, $separator, $options->yamlFixMergeAnchorToSpec, $resultIndex);
    }

    private function objects(Node $root, string $separator, bool $fixedMerge, int $resultIndex): string
    {
        $header = [];
        foreach (NodeTools::pairs(NodeTools::unwrap($root->content[0]), $fixedMerge) as [$key]) {
            $header[] = NodeTools::keyText($key);
        }

        $out = 0 === $resultIndex ? $this->fields($separator, ...$header) : '';
        foreach ($root->content as $item) {
            $item = NodeTools::unwrap($item);
            if (NodeKindEnum::Mapping !== $item->kind) {
                throw new FormatException('csv: every row must be an object');
            }

            $values = [];
            foreach (NodeTools::pairs($item, $fixedMerge) as [$key, $value]) {
                $values[NodeTools::keyText($key)] = $value;
            }

            $cells = [];
            foreach ($header as $name) {
                $cells[] = isset($values[$name]) ? $this->cell($values[$name]) : '';
            }

            $out .= $this->fields($separator, ...$cells);
        }

        return $out;
    }

    private function record(string $separator, Node ...$cells): string
    {
        $texts = [];
        foreach ($cells as $cell) {
            $texts[] = $this->cell($cell);
        }

        return $this->fields($separator, ...$texts);
    }

    private function cell(Node $node): string
    {
        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar !== $node->kind) {
            throw new FormatException('csv: cannot write a nested array or map into a field');
        }

        return $node->value;
    }

    private function fields(string $separator, string ...$fields): string
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

        if ('\.' === $field || \strlen($field) !== strcspn($field, "\r\n\"") || str_contains($field, $separator)) {
            return true;
        }

        return 1 === preg_match('/^\s/u', $field);
    }
}
