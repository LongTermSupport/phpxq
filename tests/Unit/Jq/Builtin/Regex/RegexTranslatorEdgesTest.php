<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\RegexTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Quoting with `\Q...\E`, the case folded POSIX classes and properties, and flags that switch extended mode.
 *
 * @internal
 */
final class RegexTranslatorEdgesTest extends TestCase
{
    private const string CASED_LETTERS = '\p{Lu}\p{Ll}\p{Lt}';

    /**
     * @param list<string> $names
     */
    #[DataProvider('translations')]
    public function testTranslation(string $source, bool $extended, bool $ignoreCase, string $expected, array $names): void
    {
        $translated = RegexTranslator::translate($source, $extended, false, $ignoreCase);

        self::assertSame($expected, $translated->pcre);
        self::assertSame($names, $translated->groupNames);
    }

    /**
     * @return iterable<string, array{string, bool, bool, string, list<string>}>
     */
    public static function translations(): iterable
    {
        yield 'a quoted run between literals' => ['a\Qb.c\Ed', false, false, 'a\Qb.c\Ed', []];
        yield 'a slash inside a quoted run' => ['\Qa/b\E', false, false, '\Qa\E\/\Qb\E', []];
        yield 'an unterminated quoted run' => ['\Qab', false, false, '\Qab\E', []];
        yield 'a literal after a quoted run' => ['\Qx\Ey', false, false, '\Qx\Ey', []];
        yield 'two quoted runs keep the text before them' => ['p\Qx\E\Qy\Ez', false, false, 'p\Qx\E\Qy\Ez', []];
        yield 'an unterminated run ending in a slash' => ['\Qab/', false, false, '\Qab\E\/\Q\E', []];
        yield 'upper in a class folds to every cased letter' => ['[[:upper:]]', false, true, '[' . self::CASED_LETTERS . ']', []];
        yield 'lower in a class folds to every cased letter' => ['[[:lower:]x]', false, true, '[' . self::CASED_LETTERS . 'x]', []];
        yield 'upper in a class is kept without the modifier' => ['[x[:upper:]]', false, false, '[x[:upper:]]', []];
        yield 'other classes are kept with the modifier' => ['[[:alpha:]]', false, true, '[[:alpha:]]', []];
        yield 'a class mixing digits and lower' => ['[[:digit:][:lower:]]', false, true, '[[:digit:]' . self::CASED_LETTERS . ']', []];
        yield 'an unterminated class name is copied' => ['[[:upper', false, true, '[[:upper', []];
        yield 'a class after a literal member' => ['[a[:upper:]]', false, true, '[a' . self::CASED_LETTERS . ']', []];
        yield 'the upper property folds with the modifier' => ['\p{Upper}', false, true, '[' . self::CASED_LETTERS . ']', []];
        yield 'the lower property folds with the modifier' => ['\p{Lower}', false, true, '[' . self::CASED_LETTERS . ']', []];
        yield 'the upper property is exact without the modifier' => ['\p{Upper}', false, false, '[\p{Lu}]', []];
        yield 'the lower property inside a class folds' => ['[\p{Lower}]', false, true, '[' . self::CASED_LETTERS . ']', []];
        yield 'a negated upper property folds' => ['\P{Upper}', false, true, '[^' . self::CASED_LETTERS . ']', []];
        yield 'turning extended mode off lets a hash start a group again' => ['#(?<n>a)' . "\n" . '(?-x)#(?<m>b)', true, false, '#(?<n>a)' . "\n" . '(?-x)#(?<m>b)', ['m']];
        yield 'turning extended mode off after other flags' => ['(?i-x)#(?<m>b)', true, false, '(?i-x)#(?<m>b)', ['m']];
        yield 'turning extended mode on hides the comment text' => ['(?x)#(?<n>a)' . "\n" . 'b', false, false, '(?x)#(?<n>a)' . "\n" . 'b', []];
        yield 'turning extended mode on after other flags' => ['(?x-i)#(?<n>a)' . "\n" . 'b', false, false, '(?x-i)#(?<n>a)' . "\n" . 'b', []];
        yield 'unrelated flags leave extended mode on' => ['(?i)#(?<n>a)', true, false, '(?i)#(?<n>a)', []];
        yield 'extended mode off from the start sees the group' => ['(?-x)#(?<n>a)', true, false, '(?-x)#(?<n>a)', ['n']];
    }
}
