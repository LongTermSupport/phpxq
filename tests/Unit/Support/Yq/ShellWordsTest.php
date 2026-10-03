<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Yq;

use LTS\PhpXq\Tests\Support\Yq\ShellWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ShellWordsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('splittableProvider')]
    public function testSplitsSimpleCommands(string $command, array $expected): void
    {
        self::assertSame($expected, new ShellWords()->split($command));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function splittableProvider(): iterable
    {
        yield 'plain words' => ['yq -P sample.yml', ['yq', '-P', 'sample.yml']];
        yield 'single quotes keep spaces and double quotes' => ["yq '.a + \"x y\"' f.yml", ['yq', '.a + "x y"', 'f.yml']];
        yield 'double quotes unescape' => ['yq --a="b c" "d\"e"', ['yq', '--a=b c', 'd"e']];
        yield 'quote glued to word' => ["yq -o='json'", ['yq', '-o=json']];
        yield 'multi line quoted expression' => ["yq '.a\n| .b' f.yml", ['yq', ".a\n| .b", 'f.yml']];
        yield 'line continuation' => ["yq \\\n -P f.yml", ['yq', '-P', 'f.yml']];
        yield 'empty quoted word' => ["yq ''", ['yq', '']];
        yield 'trailing newline ignored' => ["yq .\n", ['yq', '.']];
        yield 'pipe inside quotes is data' => ["yq '.a | .b'", ['yq', '.a | .b']];
    }

    #[DataProvider('unsupportedProvider')]
    public function testRejectsShellSyntaxItCannotRepresent(string $command): void
    {
        self::assertNull(new ShellWords()->split($command));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedProvider(): iterable
    {
        yield 'pipe' => ['cat a.yml | yq .'];
        yield 'redirect out' => ['yq . a.yml > b.yml'];
        yield 'redirect in' => ['yq . < a.yml'];
        yield 'semicolon' => ['yq .; yq .'];
        yield 'two statements on lines' => ["yq . a.yml\nyq . b.yml"];
        yield 'and list' => ['yq . && yq .'];
        yield 'command substitution' => ['yq $(echo .)'];
        yield 'unterminated quote' => ["yq '.a"];
    }
}
