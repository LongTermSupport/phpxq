<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Token\Scanner;
use LTS\PhpXq\Yaml\Token\ScanToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ScannerTest extends TestCase
{
    private const string SIMPLE_MAP = "a: 1\n";

    private const string VALUE_SUFFIX = ": v\n";

    public function testBlockMappingTokens(): void
    {
        self::assertSame(
            [
                ScanToken::STREAM_START,
                ScanToken::BLOCK_MAPPING_START,
                ScanToken::KEY,
                ScanToken::SCALAR,
                ScanToken::VALUE,
                ScanToken::SCALAR,
                ScanToken::BLOCK_END,
                ScanToken::STREAM_END,
            ],
            $this->types(self::SIMPLE_MAP),
        );
    }

    public function testBlockSequenceTokens(): void
    {
        self::assertSame(
            [ScanToken::STREAM_START, ScanToken::BLOCK_SEQUENCE_START, ScanToken::BLOCK_ENTRY, ScanToken::SCALAR, ScanToken::BLOCK_ENTRY, ScanToken::SCALAR, ScanToken::BLOCK_END, ScanToken::STREAM_END],
            $this->types("- a\n- b\n"),
        );
    }

    public function testFlowSequenceTokens(): void
    {
        self::assertSame(
            [
                ScanToken::STREAM_START,
                ScanToken::FLOW_SEQUENCE_START,
                ScanToken::SCALAR,
                ScanToken::FLOW_ENTRY,
                ScanToken::SCALAR,
                ScanToken::FLOW_SEQUENCE_END,
                ScanToken::STREAM_END,
            ],
            $this->types('[a, b]'),
        );
    }

    public function testDocumentMarkersAndDirectives(): void
    {
        $tokens = $this->tokens("%YAML 1.1\n%TAG !e! tag:e.com,2000:\n---\na\n...\n");

        self::assertSame(ScanToken::VERSION_DIRECTIVE, $tokens[1]->type);
        self::assertSame('1.1', $tokens[1]->value);
        self::assertSame(ScanToken::TAG_DIRECTIVE, $tokens[2]->type);
        self::assertSame('!e!', $tokens[2]->value);
        self::assertSame('tag:e.com,2000:', $tokens[2]->suffix);
        self::assertSame(ScanToken::DOCUMENT_START, $tokens[3]->type);
        self::assertSame(ScanToken::DOCUMENT_END, $tokens[5]->type);
    }

    public function testScalarStylesAndValues(): void
    {
        $tokens = $this->tokens("- plain text\n- 'it''s'\n- \"a\\tb\"\n- |\n  lit\n- >-\n  fol\n  ded\n");
        $scalar = array_values(array_filter($tokens, static fn (ScanToken $t): bool => ScanToken::SCALAR === $t->type));

        self::assertSame(['plain text', "it's", "a\tb", "lit\n", 'fol ded'], array_map(static fn (ScanToken $t): string => $t->value, $scalar));
        self::assertSame([ScanToken::PLAIN, ScanToken::SINGLE, ScanToken::DOUBLE, ScanToken::LITERAL, ScanToken::FOLDED], array_map(static fn (ScanToken $t): int => $t->style, $scalar));
    }

    public function testTagsAndAnchors(): void
    {
        $tokens = $this->tokens("&a !!str x\n");

        self::assertSame(ScanToken::ANCHOR, $tokens[1]->type);
        self::assertSame('a', $tokens[1]->value);
        self::assertSame(ScanToken::TAG, $tokens[2]->type);
        self::assertSame('!!', $tokens[2]->value);
        self::assertSame('str', $tokens[2]->suffix);
    }

    public function testPositionsAreZeroBasedCharacterColumns(): void
    {
        $tokens = $this->tokens("\u{e9}: v\nk: w\n");
        $values = array_values(array_filter($tokens, static fn (ScanToken $t): bool => ScanToken::VALUE === $t->type));

        self::assertSame([0, 1], [$values[0]->startLine, $values[0]->startColumn]);
        self::assertSame([1, 1], [$values[1]->startLine, $values[1]->startColumn]);
    }

    public function testPrepareStripsBomAndNormalisesLineBreaks(): void
    {
        self::assertSame("a\nb\nc\nd", Scanner::prepare("\xEF\xBB\xBFa\r\nb\rc\xC2\x85d"));
    }

    public function testPrepareDecodesUtf16WithABom(): void
    {
        $text = "a: \u{00E9}\u{1F600}\r\nb: 1\n";

        self::assertSame("a: \u{00E9}\u{1F600}\nb: 1\n", Scanner::prepare("\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')));
        self::assertSame("a: \u{00E9}\u{1F600}\nb: 1\n", Scanner::prepare("\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }

    public function testPrepareRejectsUtf16WithAnOddByteCount(): void
    {
        $this->expectException(YamlSyntaxException::class);

        Scanner::prepare("\xFF\xFEa\x00:");
    }

    public function testCrlfBreaksCountTwiceWhenLookingAheadForComments(): void
    {
        $head = static function (string $yaml): string {
            $scanner = new Scanner($yaml);
            $scanner->peek();
            $scanner->skip();
            $scanner->peek();

            return $scanner->headComment;
        };

        self::assertSame('# a', $head("# a\nk: v\n"));
        self::assertSame("# a\n", $head("# a\r\nk: v\r\n"));
    }

    #[DataProvider('invalidInputProvider')]
    public function testPrepareRejectsInvalidInput(string $yaml, string $message): void
    {
        try {
            Scanner::prepare($yaml);
        } catch (YamlSyntaxException $yamlSyntaxException) {
            self::assertStringContainsString($message, $yamlSyntaxException->getMessage());

            return;
        }

        self::fail('expected a syntax error');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'control character' => ["a: \x01", 'control characters are not allowed'];
        yield 'invalid utf-8' => ["a: \xFF", 'invalid leading UTF-8 octet'];
    }

    public function testUnterminatedQuoteIsAnError(): void
    {
        $this->expectException(YamlSyntaxException::class);
        $this->types('a: "x');
    }

    public function testTokensArriveLazily(): void
    {
        $scanner = new Scanner(self::SIMPLE_MAP);

        self::assertSame(ScanToken::STREAM_START, $scanner->peek()->type);
        $scanner->skip();
        self::assertSame(ScanToken::BLOCK_MAPPING_START, $scanner->peek()->type);
    }

    public function testCommentsFillTheBuffersOfTheTokenTheyBelongTo(): void
    {
        $scanner = new Scanner("# head\na: 1 # line\n# foot\n\nb: 2\n");
        $seen    = [];
        while (true) {
            $token = $scanner->peek();
            if ('' !== $scanner->headComment) {
                $seen[]               = 'head:' . $scanner->headComment;
                $scanner->headComment = '';
            }

            if ('' !== $scanner->lineComment) {
                $seen[]               = 'line:' . $scanner->lineComment;
                $scanner->lineComment = '';
            }

            if ('' !== $scanner->footComment) {
                $seen[]               = 'foot:' . $scanner->footComment;
                $scanner->footComment = '';
            }

            $scanner->skip();
            if (ScanToken::STREAM_END === $token->type) {
                break;
            }
        }

        self::assertSame(['head:# head', 'line:# line', 'foot:# foot'], $seen);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('goldenProvider')]
    public function testTokenStreamsMatchTheRecordedGoldenDumps(string $yaml, array $expected): void
    {
        self::assertSame($expected, $this->dump($yaml));
    }

    /**
     * Each case in the fixture is a YAML text and the dump of its tokens: type, start and end as
     * index:line:column, then the value, tag suffix and style where set, followed by the comments the
     * scanner logged. Failures end the dump with the error message and its 1-based line and column.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function goldenProvider(): iterable
    {
        $json = file_get_contents(__DIR__ . '/../../../Fixtures/Yaml/scanner-tokens.json');
        self::assertIsString($json);
        $cases = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name']);
            self::assertIsString($case['yaml']);
            self::assertIsArray($case['expected']);

            /** @var list<string> $expected */
            $expected = $case['expected'];

            yield $case['name'] => [$case['yaml'], $expected];
        }
    }

    #[DataProvider('simpleKeyLengthProvider')]
    public function testSimpleKeysAreLimitedTo1024Characters(string $yaml, bool $isKey): void
    {
        $types = $this->types($yaml);

        self::assertSame($isKey, \in_array(ScanToken::KEY, $types, true));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function simpleKeyLengthProvider(): iterable
    {
        yield 'plain key of 1024' => [str_repeat('k', 1024) . self::VALUE_SUFFIX, true];
        yield 'quoted key of 1024' => ["'" . str_repeat('k', 1022) . "'" . self::VALUE_SUFFIX, true];
        yield 'explicit key of 1100' => ['? ' . str_repeat('k', 1100) . "\n:\n", true];
    }

    #[DataProvider('overlongKeyProvider')]
    public function testKeysOver1024CharactersAreAnError(string $yaml, string $message): void
    {
        try {
            $this->types($yaml);
        } catch (YamlSyntaxException $yamlSyntaxException) {
            self::assertStringContainsString($message, $yamlSyntaxException->getMessage());

            return;
        }

        self::fail('expected a syntax error');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function overlongKeyProvider(): iterable
    {
        yield 'plain key' => [str_repeat('k', 1025) . self::VALUE_SUFFIX, 'mapping values are not allowed in this context'];
        yield 'quoted key' => ["'" . str_repeat('k', 1023) . "'" . self::VALUE_SUFFIX, 'mapping values are not allowed in this context'];
    }

    public function testLongStreamsKeepEveryTokenAcrossBufferCompaction(): void
    {
        $yaml = '';
        for ($i = 1; $i <= 300; ++$i) {
            $yaml .= 'k' . $i . self::VALUE_SUFFIX;
        }

        $values = [];
        foreach ($this->tokens($yaml) as $token) {
            if (ScanToken::SCALAR === $token->type) {
                $values[] = $token->value;
            }
        }

        self::assertCount(600, $values);
        self::assertSame(['k1', 'v', 'k2', 'v'], \array_slice($values, 0, 4));
        self::assertSame(['k300', 'v'], \array_slice($values, -2));
    }

    public function testPeekReturnsTheSameTokenUntilItIsSkipped(): void
    {
        $scanner = new Scanner(self::SIMPLE_MAP);
        $first   = $scanner->peek();

        self::assertSame($first, $scanner->peek());
        $scanner->skip();
        self::assertNotSame($first, $scanner->peek());
    }

    /**
     * @return list<string>
     */
    private function dump(string $yaml): array
    {
        $names = [
            ScanToken::STREAM_START       => 'STREAM_START',
            ScanToken::STREAM_END         => 'STREAM_END',
            ScanToken::VERSION_DIRECTIVE  => 'VERSION_DIRECTIVE',
            ScanToken::TAG_DIRECTIVE      => 'TAG_DIRECTIVE',
            ScanToken::DOCUMENT_START     => 'DOCUMENT_START',
            ScanToken::DOCUMENT_END       => 'DOCUMENT_END',
            ScanToken::BLOCK_SEQUENCE_START => 'BLOCK_SEQUENCE_START',
            ScanToken::BLOCK_MAPPING_START => 'BLOCK_MAPPING_START',
            ScanToken::BLOCK_END          => 'BLOCK_END',
            ScanToken::FLOW_SEQUENCE_START => 'FLOW_SEQUENCE_START',
            ScanToken::FLOW_SEQUENCE_END  => 'FLOW_SEQUENCE_END',
            ScanToken::FLOW_MAPPING_START => 'FLOW_MAPPING_START',
            ScanToken::FLOW_MAPPING_END   => 'FLOW_MAPPING_END',
            ScanToken::BLOCK_ENTRY        => 'BLOCK_ENTRY',
            ScanToken::FLOW_ENTRY         => 'FLOW_ENTRY',
            ScanToken::KEY                => 'KEY',
            ScanToken::VALUE              => 'VALUE',
            ScanToken::ALIAS              => 'ALIAS',
            ScanToken::ANCHOR             => 'ANCHOR',
            ScanToken::TAG                => 'TAG',
            ScanToken::SCALAR             => 'SCALAR',
        ];
        $json  = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
        $scanner = new Scanner($yaml);
        $scanner->logComments();
        $out = [];
        try {
            while (true) {
                $token = $scanner->peek();
                $line  = \sprintf('%s %d:%d:%d-%d:%d:%d', $names[$token->type], $token->startIndex, $token->startLine, $token->startColumn, $token->endIndex, $token->endLine, $token->endColumn);
                if ('' !== $token->value) {
                    $line .= ' v=' . json_encode($token->value, $json);
                }

                if ('' !== $token->suffix) {
                    $line .= ' x=' . json_encode($token->suffix, $json);
                }

                if (0 !== $token->style) {
                    $line .= ' st=' . $token->style;
                }

                $out[] = $line;
                $scanner->skip();
                if (ScanToken::STREAM_END === $token->type) {
                    break;
                }
            }
        } catch (YamlSyntaxException $yamlSyntaxException) {
            $out[] = 'ERR ' . $yamlSyntaxException->getMessage() . ' @' . $yamlSyntaxException->yamlLine . ':' . $yamlSyntaxException->yamlColumn;
        }

        foreach ($scanner->loggedComments() as $comment) {
            if ('' !== $comment->head) {
                $kind = 'head';
                $text = $comment->head;
            } elseif ('' !== $comment->line) {
                $kind = 'line';
                $text = $comment->line;
            } else {
                $kind = 'foot';
                $text = $comment->foot;
            }

            $out[] = \sprintf('#%s scan=%d tok=%d %d:%d:%d-%d:%d:%d %s', $kind, $comment->scanIndex, $comment->tokenIndex, $comment->startIndex, $comment->startLine, $comment->startColumn, $comment->endIndex, $comment->endLine, $comment->endColumn, json_encode($text, $json));
        }

        return $out;
    }

    /**
     * @return list<ScanToken>
     */
    private function tokens(string $yaml): array
    {
        $scanner = new Scanner($yaml);
        $tokens  = [];
        while (true) {
            $token    = $scanner->peek();
            $tokens[] = $token;
            $scanner->skip();
            if (ScanToken::STREAM_END === $token->type) {
                return $tokens;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function types(string $yaml): array
    {
        return array_map(static fn (ScanToken $t): int => $t->type, $this->tokens($yaml));
    }
}
