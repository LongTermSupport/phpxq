<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Tests\Support\Jq\StandardProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Outputs of `sub`/`gsub` with several or no replacement outputs, and of deleting several object members at
 * once, as the full builtin library gives them.
 *
 * @internal
 */
final class SubstitutionAndDeletionTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('programs')]
    public function testProgram(string $program, array $expected): void
    {
        self::assertSame($expected, StandardProgram::outputs($program));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function programs(): iterable
    {
        yield 'gsub with two replacement outputs' => ['"abcab" | [gsub("(?<x>[ac])"; "<\(.x)>", "[\(.x)]")]', ['["<a>b<c><a>b","[a]b[c][a]b"]']];
        yield 'gsub with two constant outputs' => ['"a,b,c" | [gsub(","; "1", "2")]', ['["a1b1c","a2b2c"]']];
        yield 'gsub with no replacement output keeps the input' => ['"a,b" | [gsub(","; empty)]', ['["a,b"]']];
        yield 'gsub with a null replacement drops the match' => ['"xay" | [gsub("a"; null)]', ['["xy"]']];
        yield 'gsub on non-ASCII text' => ['"éaéa" | gsub("a"; "ÿ")', ['"éÿéÿ"']];
        yield 'gsub with a number replacement fails' => ['try ("a" | gsub("a"; 1)) catch .', ['"string (\"\") and number (1) cannot be added"']];
        yield 'gsub with replacement output counts varying per match' => ['"a1b22" | [gsub("(?<d>[0-9])"; if .d == "1" then "x", "y" else "z" end)]', ['["axbzz","ay"]']];
        yield 'sub replaces the first match only' => ['"aXbX" | [sub("X"; "1", "2")]', ['["a1bX","a2bX"]']];
        yield 'delete every member' => ['{"a":1,"b":2,"1":3} | del(.[])', ['{}']];
        yield 'delete some members keeps the order of the rest' => ['{"a":1,"b":2,"1":3,"c":4} | del(.a, .["1"])', ['{"b":2,"c":4}']];
        yield 'delete a missing member' => ['{"a":1} | del(.z)', ['{"a":1}']];
        yield 'delete an array index of an object fails' => ['try ({"a":1} | delpaths([["a"],[0]])) catch .', ['"Cannot delete field at array index of object"']];
    }
}
