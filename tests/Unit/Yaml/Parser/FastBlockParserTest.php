<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Tests\Support\SeededRandom;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\FastBlockParser;
use LTS\PhpXq\Yaml\Parser\StreamParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fast path must produce exactly the tree the full parser produces, positions included, or decline.
 *
 * @internal
 */
#[CoversClass(FastBlockParser::class)]
final class FastBlockParserTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAcceptedDocumentsMatchTheFullParser(string $yaml): void
    {
        $fast = FastBlockParser::parse($yaml);

        self::assertNotNull($fast, 'the fast path should accept this document');
        $documents = iterator_to_array(new StreamParser($yaml)->documents(), false);
        self::assertCount(1, $documents);
        self::assertSame(self::dump($documents[0]), self::dump($fast));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'flat mapping' => ["a: 1\nb: two\nc: ~\n"];
        yield 'empty values' => ["a:\nb:\n"];
        yield 'plain sequence' => ["- a\n- 1\n- true\n"];
        yield 'nested block' => ["a:\n  b:\n    - x\n    - y\n  c: d\n"];
        yield 'indentless sequence' => ["a:\n- b\n- c\nd: 1\n"];
        yield 'sequence of mappings' => ["- a: 1\n  b: 2\n- c\n"];
        yield 'item mapping with nested sequence' => ["- a:\n  - x\n  - y\n  b: 1\n- c: 2\n"];
        yield 'blank lines' => ["\na: 1\n\n\nb:\n\n  c: 2\n\n"];
        yield 'no trailing newline' => ['a: 1'];
        yield 'wide spacing' => ["a:    1\nb:\n    c:   d e   f\n"];
        yield 'dash with extra spaces' => ["-   a: 1\n    b: 2\n-   c\n"];
        yield 'special scalars' => ["a: -5\nb: .5\nc: 0x1F\nd: 2001-12-14\ne: a:b\nf: http://x.y/z\ng: a\"b'c\nh: <<\n"];
        yield 'repeated texts resolve alike' => ["a: 1\nb: 1\nc: true\nd: true\ne: 1.5\nf: 1.5\ng: ~\nh: ~\ni: 007\nj: 007\n"];
        yield 'null value then dedent' => ["a:\n  b:\nc: 1\n"];
        yield 'nested empty then sibling' => ["a:\n  b:\n  c: 1\n"];
        yield 'quoted scalars' => ["a: 'x y'\nb: \"p q\"\n"];
        yield 'quoted keys and items' => ["'a b': 1\n\"c:d\": 'e # f'\nl:\n  - 'x'\n  - \"y\"\n"];
        yield 'empty quoted' => ["a: ''\nb: \"\"\n"];
        yield 'line comments' => ["a: 1 # c\nb: 'x' # d  e\nl:\n  - z # f\n"];
        yield 'head comments' => ["# top\na: 1\n# two\n# lines\nb:\n  # inner\n  c: 2\n  d: 3\n"];
        yield 'head comment on sequence item' => ["l:\n  # one\n  - a\n  # two\n  - b\n"];
        yield 'comment then blank then key' => ["a: 1\n\n# c\nb: 2\n"];
        yield 'deep' => ["a:\n  b:\n    c:\n      d:\n        - 1\n        - e: 2\n          f:\n            - g\n"];
    }

    #[DataProvider('declined')]
    public function testDeclinedDocuments(string $yaml): void
    {
        self::assertNull(FastBlockParser::parse($yaml));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declined(): iterable
    {
        yield 'empty' => [''];
        yield 'only blank lines' => ["\n\n"];
        yield 'comment above blank gap' => ["# c\n\na: 1\n"];
        yield 'comment at wrong indent' => ["a:\n  # c\n  b: 1\n    # d\n  e: 2\n"];
        yield 'comment above item mapping' => ["# c\n- a: 1\n"];
        yield 'comment after key without value' => ["a: # c\n  b: 1\n"];
        yield 'comment only document' => ["# c\n"];
        yield 'quoted with escape' => ["a: \"x\\ny\"\n"];
        yield 'quoted with doubled quote' => ["a: 'it''s'\n"];
        yield 'quoted then text' => ["a: 'x' y\n"];
        yield 'comment with trailing blank' => ["a: 1 # c \n"];
        yield 'comment with tab' => ["a: 1 # c\td\n"];
        yield 'hash inside a plain value' => ["a: b#c\n"];
        yield 'flow collection' => ["a: [1, 2]\n"];
        yield 'anchor' => ["a: &x 1\n"];
        yield 'alias' => ["a: *x\n"];
        yield 'tag' => ["a: !!str 1\n"];
        yield 'block scalar' => ["a: |\n  x\n"];
        yield 'document marker' => ["---\na: 1\n"];
        yield 'second document' => ["a: 1\n---\nb: 2\n"];
        yield 'document end' => ["a: 1\n...\n"];
        yield 'directive' => ["%YAML 1.2\n---\na: 1\n"];
        yield 'multi line plain' => ["a: one\n  two\nb: 1\n"];
        yield 'multi line plain in sequence' => ["- one\n  two\n"];
        yield 'deeper after scalar value' => ["a: 1\n  b: 2\n"];
        yield 'bad dedent' => ["a:\n    b: 1\n  c: 2\n"];
        yield 'sequence at mapping indent without key' => ["a: 1\n- x\n"];
        yield 'key at sequence indent' => ["- a\nb: 1\n"];
        yield 'dash alone' => ["-\n  a: 1\n"];
        yield 'nested dash' => ["- - a\n  - b\n"];
        yield 'explicit key' => ["? a\n: b\n"];
        yield 'root scalar' => ["hello\n"];
        yield 'root indented' => ["  a: 1\n"];
        yield 'tab' => ["a:\t1\n"];
        yield 'carriage return' => ["a: 1\r\nb: 2\r\n"];
        yield 'non ascii' => ["a: caf\u{e9}\n"];
        yield 'bom' => ["\u{feff}a: 1\n"];
        yield 'trailing spaces' => ["a: 1  \n"];
        yield 'colon space inside value' => ["a: b: c\n"];
        yield 'colon at end of value' => ["a: b:\n"];
        yield 'key with space before colon' => ["a : 1\n"];
        yield 'value starting with indicator' => ["a: @x\n"];
        yield 'value starting with dash space' => ["a: - x\n"];
        yield 'continuation line without key' => ["a:\n  just text\n"];
        yield 'comment after key' => ["a: # c\n  b: 1\n"];
    }

    public function testGeneratedDocumentsMatchTheFullParserOrAreDeclined(): void
    {
        $random   = new SeededRandom(2024);
        $accepted = 0;
        for ($i = 0; $i < 400; ++$i) {
            $lines = [];
            self::generate($random, 0, 0 === $random->between(0, 2) ? 2 : 0, $lines);
            $yaml = implode("\n", $lines) . "\n";
            $fast = FastBlockParser::parse($yaml);
            if (!$fast instanceof Node) {
                continue;
            }

            ++$accepted;
            $documents = iterator_to_array(new StreamParser($yaml)->documents(), false);
            self::assertCount(1, $documents, $yaml);
            self::assertSame(self::dump($documents[0]), self::dump($fast), $yaml);
        }

        self::assertGreaterThan(150, $accepted, 'the generator should mostly produce fast-path documents');
    }

    public function testManyDistinctScalarsStillMatchTheFullParser(): void
    {
        // more distinct texts than the tag memo holds, with repeats before and after it fills up
        $lines = [];
        for ($i = 0; $i < 2600; ++$i) {
            $value = match ($i % 4) {
                0       => (string)$i,
                1       => 'v' . $i,
                2       => 'true',
                default => '1.5',
            };

            $lines[] = 'key' . $i . ': ' . $value;
        }

        $yaml = implode("\n", $lines) . "\n";
        $fast = FastBlockParser::parse($yaml);

        self::assertNotNull($fast);
        $documents = iterator_to_array(new StreamParser($yaml)->documents(), false);
        self::assertSame(self::dump($documents[0]), self::dump($fast));
    }

    /**
     * @param list<string> $lines
     */
    private static function generate(SeededRandom $random, int $depth, int $indent, array &$lines): void
    {
        $pad   = str_repeat(' ', $indent);
        $isSeq = 0 === $random->between(0, 2);
        $count = $random->between(1, 4);
        $words = ["'q r'", '"s t"', 'u # v', 'w #x', 'alpha', 'beta gamma', '12', '-3', '4.5', 'true', 'null', '~', 'x_y', 'a:b', 'http://h/p', '2001-12-14', '<<', 'k-1', 'a b  c'];
        for ($i = 0; $i < $count; ++$i) {
            if (0 === $random->between(0, 15)) {
                $lines[] = '';
            }

            if (0 === $random->between(0, 9)) {
                $lines[] = $pad . '# note ' . $i;
            }

            $word   = $random->pick(...$words);
            $nested = $depth < 4 && 0 === $random->between(0, 2);
            if ($isSeq) {
                if ($nested) {
                    if (0 === $random->between(0, 1)) {
                        $lines[] = $pad . '- k' . $i . ':';
                        self::generate($random, $depth + 1, $indent + 2 + $random->between(0, 2), $lines);
                    } else {
                        $lines[] = $pad . '-';
                        self::generate($random, $depth + 1, $indent + 2, $lines);
                    }
                } else {
                    $lines[] = $pad . '- ' . (1 === $i % 2 ? $word : 'k' . $i . ': ' . $word);
                }

                continue;
            }

            if ($nested) {
                $lines[] = $pad . 'k' . $i . ':';
                if (0 === $random->between(0, 3)) {
                    $lines[] = $pad . '- ' . $word;
                } else {
                    self::generate($random, $depth + 1, $indent + $random->between(1, 4), $lines);
                }
            } else {
                $lines[] = $pad . 'k' . $i . (0 === $random->between(0, 5) ? ':' : ': ' . $word);
            }
        }
    }

    private static function dump(Node $node): string
    {
        $out = $node->kind->name . '|' . $node->tag . '|' . $node->style->name . '|' . $node->value . '|' . $node->anchor . '|'
            . $node->headComment . '|' . $node->lineComment . '|' . $node->footComment . '|' . $node->line . ':' . $node->column . '|'
            . (int)$node->tagExplicit . (int)$node->explicitStart . (int)$node->explicitEnd . '|' . $node->directives . '|' . (int)$node->commentsCleared . '{';
        foreach ($node->content as $child) {
            $out .= self::dump($child) . ',';
        }

        return $out . '}';
    }
}
