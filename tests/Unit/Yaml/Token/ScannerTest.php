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
            $this->types("a: 1\n"),
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
        $scanner = new Scanner("a: 1\n");

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
