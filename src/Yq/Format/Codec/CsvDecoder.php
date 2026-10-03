<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * CSV and TSV input: the first record names the columns and every later record becomes a mapping in one
 * top-level sequence. With auto-parse on (the default) each field is read as a YAML snippet, so `1`,
 * `true` and `cool: true` become an int, a bool and a map; with it off the field is a plain scalar.
 */
final readonly class CsvDecoder implements DecoderInterface
{
    private const string SIMPLE_FIELD = '/^(?!---|\.\.\.)(?:[A-Za-z0-9_.+\/]|-[A-Za-z0-9_.+\/])[A-Za-z0-9_.+\/ -]*(?<! )$/D';

    public function __construct(private FormatEnum $format = FormatEnum::Csv, private YamlParserInterface $parser = new YamlParser())
    {
    }

    public function format(): FormatEnum
    {
        return $this->format;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        $separator = FormatEnum::Tsv === $this->format ? $options->tsvSeparator : $options->csvSeparator;
        $records   = $this->records($input, '' === $separator ? ',' : $separator);
        if ([] === $records) {
            yield Node::document(Node::scalar('', CoreSchema::TAG_NULL));

            return;
        }

        $header = array_shift($records);
        $rows   = [];
        foreach ($records as $record) {
            $content = [];
            foreach ($header as $column => $name) {
                $content[] = Node::scalar($name, CoreSchema::TAG_STR);
                $content[] = $this->field($record[$column] ?? '', $options->csvAutoParse);
            }

            $rows[] = Node::mapping($content);
        }

        yield Node::document(Node::sequence($rows));
    }

    private function field(string $text, bool $autoParse): Node
    {
        if (!$autoParse) {
            return Node::scalar($text);
        }

        if ('' === $text) {
            return Node::scalar('', CoreSchema::TAG_NULL);
        }

        if (1 === preg_match(self::SIMPLE_FIELD, $text)) {
            return Node::scalar($text);
        }

        try {
            $documents = [...$this->parser->parse($text)];
        } catch (YamlSyntaxException) {
            return Node::scalar($text, CoreSchema::TAG_STR);
        }

        if (1 !== \count($documents)) {
            return Node::scalar($text, CoreSchema::TAG_STR);
        }

        $root = $documents[0]->root();

        return NodeKindEnum::Alias === $root->kind ? Node::scalar($text, CoreSchema::TAG_STR) : $root;
    }

    /**
     * RFC 4180 records with the leniency of Go's reader: blank lines are skipped, `\r\n` and `\n` both end
     * a record, and a quote inside an unquoted field is an error.
     *
     * @return list<list<string>>
     */
    private function records(string $input, string $separator): array
    {
        if (str_starts_with($input, "\u{FEFF}")) {
            $input = substr($input, 3);
        }

        $length  = \strlen($input);
        $width   = \strlen($separator);
        $records = [];
        $pos     = 0;
        while ($pos < $length) {
            $lineEnd = strcspn($input, "\r\n", $pos);
            if (0 === $lineEnd) {
                $pos += "\r" === $input[$pos] && "\n" === ($input[$pos + 1] ?? '') ? 2 : 1;

                continue;
            }

            $start  = $pos;
            $record = [];
            while (true) {
                if ($pos < $length && '"' === $input[$pos]) {
                    [$field, $pos] = $this->quoted($input, $pos + 1, $length);
                    if ($pos < $length && "\r" !== $input[$pos] && "\n" !== $input[$pos] && substr($input, $pos, $width) !== $separator) {
                        throw new FormatException('csv: parse error on line ' . (substr_count($input, "\n", 0, $pos) + 1) . ': extraneous or missing " in quoted-field');
                    }
                } else {
                    $stop  = $this->fieldEnd($input, $pos, $length, $separator);
                    $field = substr($input, $pos, $stop - $pos);
                    if (str_contains($field, '"')) {
                        throw new FormatException('csv: parse error on line ' . (substr_count($input, "\n", 0, $pos) + 1) . ': bare " in non-quoted-field');
                    }

                    $pos   = $stop;
                }

                $record[] = $field;
                if ($pos < $length && substr($input, $pos, $width) === $separator) {
                    $pos += $width;

                    continue;
                }

                if ($pos < $length) {
                    $pos += "\r" === $input[$pos] && "\n" === ($input[$pos + 1] ?? '') ? 2 : 1;
                }

                break;
            }

            if ([] !== $records && \count($record) !== \count($records[0])) {
                throw new FormatException('csv: record on line ' . (substr_count($input, "\n", 0, $start) + 1) . ': wrong number of fields');
            }

            $records[] = $record;
        }

        return $records;
    }

    private function fieldEnd(string $input, int $pos, int $length, string $separator): int
    {
        $stop = $pos + strcspn($input, "\r\n" . $separator[0], $pos);
        while ($stop < $length && "\r" !== $input[$stop] && "\n" !== $input[$stop] && substr($input, $stop, \strlen($separator)) !== $separator) {
            $stop += 1 + strcspn($input, "\r\n" . $separator[0], $stop + 1);
        }

        return $stop;
    }

    /**
     * @return array{string, int} the unquoted text and the position after the closing quote
     */
    private function quoted(string $input, int $pos, int $length): array
    {
        $out = '';
        while (true) {
            $quote = strpos($input, '"', $pos);
            if (false === $quote) {
                throw new FormatException('csv: extraneous or missing " in quoted-field');
            }

            $out .= substr($input, $pos, $quote - $pos);
            if ('"' === ($input[$quote + 1] ?? '')) {
                $out .= '"';
                $pos = $quote + 2;

                continue;
            }

            $pos = $quote + 1;

            break;
        }

        return [$out, min($pos, $length)];
    }
}
