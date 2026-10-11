<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\JqExitCode;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Strings that reach jq from outside the input stream (named and positional arguments, raw files and the program
 * text) have invalid UTF-8 replaced with U+FFFD, as jq does, so every string a program sees is valid UTF-8.
 *
 * @internal
 */
#[CoversClass(JqApplication::class)]
#[Medium]
final class JqApplicationInvalidUtf8Test extends TestCase
{
    /**
     * @param list<string> $args
     */
    #[DataProvider('externalStrings')]
    public function testInvalidUtf8FromOutsideTheInputIsReplaced(array $args, string $expected): void
    {
        $result = new CliRunner()->run(['jq', '-nc', ...$args]);

        self::assertSame('', $result->stderr);
        self::assertSame(JqExitCode::OK, $result->exitCode);
        self::assertSame($expected, $result->stdout);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function externalStrings(): iterable
    {
        yield 'named argument'      => [['--arg', 'x', "a\xFF", '$x | explode'], "[97,65533]\n"];
        yield 'caught as before'    => [['--arg', 'x', "a\xFF", 'try ($x | explode) catch "c", "after"'], "[97,65533]\n\"after\"\n"];
        yield 'positional argument' => [['$ARGS.positional[0] | explode', '--args', "\xC3"], "[65533]\n"];
        yield 'program literal'     => [["\"a\xFF\" | explode"], "[97,65533]\n"];
    }

    public function testARawFileAgreesOnItsLengthInCodepoints(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phpxq-rawfile-');
        self::assertIsString($file);

        try {
            file_put_contents($file, "\xFF\xFEab");
            $result = new CliRunner()->run(['jq', '-nc', '--rawfile', 'x', $file, '$x | [length, (explode | length)]']);
        } finally {
            unlink($file);
        }

        self::assertSame("[4,4]\n", $result->stdout);
    }
}
