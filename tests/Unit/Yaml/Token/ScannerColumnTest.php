<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Tests\Support\GrowthProbe;
use LTS\PhpXq\Yaml\Token\Scanner;
use LTS\PhpXq\Yaml\Token\ScanToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Token columns count characters, not bytes, and finding them stays linear in the length of a line.
 *
 * @internal
 */
#[Medium]
final class ScannerColumnTest extends TestCase
{
    private const string ACCENT = "\u{e9}";

    /**
     * Swapping every multibyte character for one ASCII character leaves every token position unchanged, so the
     * multibyte column count agrees with the plain byte count of the ASCII text.
     */
    #[DataProvider('multibyteDocuments')]
    public function testMultibyteColumnsMatchTheAsciiEquivalent(string $yaml): void
    {
        $ascii = preg_replace('/[^\x00-\x7F]/u', 'e', $yaml);

        self::assertIsString($ascii);
        self::assertSame($this->positions($ascii), $this->positions($yaml));
    }

    /**
     * Every token's start column equals the number of characters between its line start and its first byte.
     */
    #[DataProvider('multibyteDocuments')]
    public function testStartColumnsCountCharacters(string $yaml): void
    {
        foreach ($this->tokens($yaml) as $token) {
            if (ScanToken::STREAM_END === $token->type || ScanToken::BLOCK_END === $token->type) {
                continue;
            }

            $lineStart = $this->lineStart($yaml, $token->startIndex);
            $expected  = mb_strlen(substr($yaml, $lineStart, $token->startIndex - $lineStart), 'UTF-8');

            self::assertSame($expected, $token->startColumn, \sprintf('token type %d at byte %d', $token->type, $token->startIndex));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multibyteDocuments(): iterable
    {
        $accent = self::ACCENT;

        yield 'flow map on one line' => ["{{$accent}a: b{$accent}, c: \"d{$accent}\", {$accent}: '{$accent}'}\n"];
        yield 'block map across lines' => ["{$accent}: 1\nk{$accent}{$accent}: [x, {$accent}y, z]\n  # {$accent}\nz: &a{$accent} v\n"];
        yield 'block sequence of flow maps' => ["- {k{$accent}: v, {$accent}{$accent}: w}\n- \"\u{1F600}\": {$accent}\n- {$accent}\n"];
        yield 'block scalar after accents' => ["{$accent}: |\n  {$accent}{$accent}\n  line\nb: >\n  {$accent}\n"];
        yield 'multi-line plain scalar' => ["k: {$accent}a\n  b{$accent}\n  c\nm: [{$accent},\n  {$accent}{$accent}, x]\n"];
    }

    public function testEndColumnsOfMultibyteScalarsCountCharacters(): void
    {
        $accent  = self::ACCENT;
        $scalars = array_values(array_filter(
            $this->tokens("[{$accent}{$accent}, \"{$accent}\", '{$accent}{$accent}{$accent}']\n"),
            static fn (ScanToken $t): bool => ScanToken::SCALAR === $t->type,
        ));

        self::assertSame([[1, 3], [5, 8], [10, 15]], array_map(static fn (ScanToken $t): array => [$t->startColumn, $t->endColumn], $scalars));
    }

    /**
     * A single long flow line with one multibyte character scales linearly with the number of entries.
     */
    public function testColumnsOnALongMultibyteLineScaleLinearly(): void
    {
        $growth = GrowthProbe::growth(function (int $entries): void {
            $this->tokens($this->longFlowLine($entries));
        }, 1500);

        self::assertLessThan(GrowthProbe::LINEAR_CEILING, $growth);
    }

    private function longFlowLine(int $entries): string
    {
        $yaml = '{' . self::ACCENT . ': 0';
        for ($i = 0; $i < $entries; ++$i) {
            $yaml .= ', k' . $i . ': v' . $i;
        }

        return $yaml . "}\n";
    }

    private function lineStart(string $yaml, int $index): int
    {
        $break = strrpos(substr($yaml, 0, $index), "\n");

        return false === $break ? 0 : $break + 1;
    }

    /**
     * @return list<array{int, int, int, int, int}> type, start line and column, end line and column of each token
     */
    private function positions(string $yaml): array
    {
        return array_map(
            static fn (ScanToken $t): array => [$t->type, $t->startLine, $t->startColumn, $t->endLine, $t->endColumn],
            $this->tokens($yaml),
        );
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
}
