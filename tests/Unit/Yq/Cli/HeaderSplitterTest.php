<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\HeaderSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HeaderSplitterTest extends TestCase
{
    #[DataProvider('splitProvider')]
    public function testSplit(string $input, string $header, string $rest): void
    {
        self::assertSame([$header, $rest], new HeaderSplitter()->split($input, false));
    }

    #[DataProvider('wholeProvider')]
    public function testWholeModeTakesTheEntireLeadingRun(string $input, string $header, string $rest): void
    {
        self::assertSame([$header, $rest], new HeaderSplitter()->split($input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wholeProvider(): iterable
    {
        $marker = HeaderSplitter::SEPARATOR_MARKER;

        yield 'no header'                      => ["a: 1\n", '', "a: 1\n"];
        yield 'comments above content'         => ["# one\n# two\na: 1\n", "# one\n# two\n", "a: 1\n"];
        yield 'indented comment'               => ["  # one\na: 1\n", "  # one\n", "a: 1\n"];
        yield 'leading blank lines'            => ["\n\na: 1\n", "\n\n", "a: 1\n"];
        yield 'blank lines around a comment'   => ["\n# c\n\n\na: 1\n", "\n# c\n\n\n", "a: 1\n"];
        yield 'directive without a separator'  => ["%YAML 1.1\na: 1\n", "%YAML 1.1\n", "a: 1\n"];
        yield 'comments and separator'         => ["# one\n---\na: 1\n", "# one\n" . $marker . "\n", "a: 1\n"];
        yield 'separator with comment'         => ["--- # c\na: 1\n", '', "--- # c\na: 1\n"];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function splitProvider(): iterable
    {
        $marker = HeaderSplitter::SEPARATOR_MARKER;

        yield 'no header'                   => ["a: 1\n", '', "a: 1\n"];
        yield 'empty'                       => ['', '', ''];
        yield 'comments above content stay'  => ["# one\n# two\na: 1\n", '', "# one\n# two\na: 1\n"];
        yield 'indented comment stays'       => ["  # one\na: 1\n", '', "  # one\na: 1\n"];
        yield 'blank lines only'             => ["\n\n", "\n\n", ''];
        yield 'leading separator'           => ["---\na: 1\n", $marker . "\n", "a: 1\n"];
        yield 'comments then separator'     => ["# one\n---\na: 1\n---\nb: 2\n", "# one\n" . $marker . "\n", "a: 1\n---\nb: 2\n"];
        yield 'comments after the separator are header' => ["---\n# one\na: 1\n", $marker . "\n# one\n", "a: 1\n"];
        yield 'blank lines are kept'        => ["# one\n\n\n---\n# two\n\na: 1\n", "# one\n\n\n" . $marker . "\n# two\n\n", "a: 1\n"];
        yield 'only comments'               => ["# comment\n", "# comment\n", ''];
        yield 'no trailing newline'         => ['#comment', "#comment\n", ''];
        yield 'directives belong to the header' => ["%YAML 1.1\n---\na: 1\n", "%YAML 1.1\n" . $marker . "\n", "a: 1\n"];
        yield 'directive without a separator' => ["%YAML 1.1\na: 1\n", '', "%YAML 1.1\na: 1\n"];
        yield 'separator with content'      => ["--- text\n", '', "--- text\n"];
        yield 'separator with comment'      => ["--- # c\na: 1\n", '', "--- # c\na: 1\n"];
        yield 'trailing blanks after dashes' => ["---  \na: 1\n", $marker . "\n", "a: 1\n"];
        yield 'crlf'                        => ["# one\r\n---\r\na: 1\r\n", "# one\n" . $marker . "\n", "a: 1\r\n"];
    }
}
