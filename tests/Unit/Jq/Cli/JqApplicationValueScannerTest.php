<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ValueScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationValueScannerTest extends TestCase
{
    #[DataProvider('provideValues')]
    public function testFindsTheExtentOfTheNextValue(string $text, int $start, string $slice): void
    {
        $scanner = new ValueScanner();

        self::assertSame(ValueScanner::FOUND, $scanner->find($text, 0));
        self::assertSame($start, strspn($text, " \t\r\n"));
        self::assertSame($slice, substr($text, $start, $scanner->end - $start));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideValues(): iterable
    {
        yield 'number' => ['  12 ', 2, '12'];

        yield 'literal' => ['true', 0, 'true'];

        yield 'string' => ['  "a b"  ', 2, '"a b"'];

        yield 'string with escaped quote' => ['"a\"b" x', 0, '"a\"b"'];

        yield 'string ending in escaped backslash' => ['"a\\\" x', 0, '"a\\\"'];

        yield 'array' => ["\n[1, [2, \"]\"]] 3", 1, '[1, [2, "]"]]'];

        yield 'object with brackets in strings' => ['{"a":"}{","b":{"c":[]}}{}', 0, '{"a":"}{","b":{"c":[]}}'];

        yield 'scalar stops at a bracket' => ['1[2]', 0, '1'];

        yield 'scalar stops at a quote' => ['1"a"', 0, '1'];

        yield 'nan' => ['nan ', 0, 'nan'];
    }

    public function testWalksThroughSeveralValues(): void
    {
        $text    = '1 "a" [2] {"b":3}true';
        $scanner = new ValueScanner();
        $slices  = [];
        $offset  = 0;
        while (ValueScanner::FOUND === $scanner->find($text, $offset)) {
            $begin    = $offset + strspn($text, " \t\r\n", $offset);
            $slices[] = substr($text, $begin, $scanner->end - $begin);
            $offset   = $scanner->end;
        }

        self::assertSame(['1', '"a"', '[2]', '{"b":3}', 'true'], $slices);
        self::assertSame(ValueScanner::NONE, $scanner->find($text, $offset));
    }

    public function testNothingButWhitespace(): void
    {
        $scanner = new ValueScanner();

        self::assertSame(ValueScanner::NONE, $scanner->find('', 0));
        self::assertSame(ValueScanner::NONE, $scanner->find(" \t\r\n", 0));
    }

    #[DataProvider('provideIncomplete')]
    public function testReportsATextThatEndsBeforeTheValueDoes(string $text): void
    {
        $scanner = new ValueScanner();

        self::assertSame(ValueScanner::INCOMPLETE, $scanner->find($text, 0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIncomplete(): iterable
    {
        yield 'open array' => ['[1, 2'];

        yield 'open object' => ['{"a":'];

        yield 'open string' => ['"abc'];

        yield 'string ending in a backslash' => ['"abc\\'];

        yield 'bracket inside an open string' => ['["a]'];

        yield 'nested' => ['[[1]'];
    }

    #[DataProvider('provideInvalid')]
    public function testReportsACharacterThatCannotStartAValue(string $text): void
    {
        $scanner = new ValueScanner();

        self::assertSame(ValueScanner::INVALID, $scanner->find($text, 0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'closing bracket' => [']'];

        yield 'closing brace' => ['}'];

        yield 'comma' => [',1'];

        yield 'colon' => [':'];
    }

    public function testNulIsPartOfAScalarToken(): void
    {
        $scanner = new ValueScanner();

        self::assertSame(ValueScanner::FOUND, $scanner->find("\0{}", 0));
        self::assertSame("\0", substr("\0{}", 0, $scanner->end));
    }
}
