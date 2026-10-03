<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\ArgumentParser;
use LTS\PhpXq\Yq\Cli\UsageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ArgumentParserTest extends TestCase
{
    public function testPlainArgumentsArePositionalsOfTheRootCommand(): void
    {
        $parsed = new ArgumentParser()->parse(['.a', 'file.yml', '-']);

        self::assertSame('', $parsed->command);
        self::assertSame(['.a', 'file.yml', '-'], $parsed->positionals);
    }

    /**
     * @param list<string> $args
     * @param list<string> $positionals
     */
    #[DataProvider('commandProvider')]
    public function testTheFirstNonFlagArgumentMayNameACommand(array $args, string $command, array $positionals): void
    {
        $parsed = new ArgumentParser()->parse($args);

        self::assertSame($command, $parsed->command);
        self::assertSame($positionals, $parsed->positionals);
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function commandProvider(): iterable
    {
        yield 'eval'                  => [['eval', '.a'], 'eval', ['.a']];
        yield 'short eval'            => [['e', '.a'], 'e', ['.a']];
        yield 'ea'                    => [['ea', '.a'], 'ea', ['.a']];
        yield 'after bool flags'      => [['-n', '-P', 'ea', '.a'], 'ea', ['.a']];
        yield 'after a valued flag'   => [['-o', 'json', 'e', '.'], 'e', ['.']];
        yield 'after inline value'    => [['-o=json', 'e', '.'], 'e', ['.']];
        yield 'flag value named e'    => [['-o', 'e', '.'], '', ['.']];
        yield 'second word not a command' => [['.a', 'e'], '', ['.a', 'e']];
        yield 'after double dash is not a command' => [['--', 'e'], '', ['e']];
        yield 'dash is an argument' => [['-', 'e'], '', ['-', 'e']];
    }

    public function testDefaultsAreFilledIn(): void
    {
        $parsed = new ArgumentParser()->parse([]);

        self::assertSame(2, $parsed->int('indent'));
        self::assertSame('auto', $parsed->string('input-format'));
        self::assertTrue($parsed->bool('unwrapScalar'));
        self::assertTrue($parsed->bool('header-preprocess'));
        self::assertFalse($parsed->bool('inplace'));
        self::assertFalse($parsed->given('indent'));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('flagFormsProvider')]
    public function testFlagForms(array $args, string $flag, bool|int|string $expected): void
    {
        $parsed = new ArgumentParser()->parse($args);

        self::assertTrue($parsed->given($flag));
        self::assertSame($expected, $parsed->values[$flag]);
    }

    /**
     * @return iterable<string, array{list<string>, string, bool|int|string}>
     */
    public static function flagFormsProvider(): iterable
    {
        yield 'long bool'              => [['--null-input'], 'null-input', true];
        yield 'long bool false'        => [['--csv-auto-parse=f'], 'csv-auto-parse', false];
        yield 'long bool true'         => [['--csv-auto-parse=TRUE'], 'csv-auto-parse', true];
        yield 'short bool'             => [['-n'], 'null-input', true];
        yield 'short bool false'       => [['-r=false'], 'unwrapScalar', false];
        yield 'long value equals'      => [['--output-format=json'], 'output-format', 'json'];
        yield 'long value space'       => [['--output-format', 'json'], 'output-format', 'json'];
        yield 'short value attached'   => [['-oy'], 'output-format', 'y'];
        yield 'short value equals'     => [['-o=json'], 'output-format', 'json'];
        yield 'short value space'      => [['-o', 'json'], 'output-format', 'json'];
        yield 'short int equals'       => [['-I=0'], 'indent', 0];
        yield 'short int attached'     => [['-I4'], 'indent', 4];
        yield 'long int'               => [['--indent', '3'], 'indent', 3];
        yield 'cluster'                => [['-inP'], 'prettyPrint', true];
        yield 'cluster ends in value'  => [['-ino', 'json'], 'output-format', 'json'];
        yield 'value with equals sign' => [['--properties-separator= :@ '], 'properties-separator', ' :@ '];
        yield 'nul output'             => [['-0'], 'nul-output', true];
        yield 'split'                  => [['-s', '.a'], 'split-exp', '.a'];
        yield 'front matter'           => [['--front-matter=extract'], 'front-matter', 'extract'];
        yield 'json shortcut'          => [['-j'], 'output-format', 'json'];
        yield 'yaml shortcut'          => [['-y'], 'output-format', 'yaml'];
    }

    public function testClusterSetsEveryBoolean(): void
    {
        $parsed = new ArgumentParser()->parse(['-inP']);

        self::assertTrue($parsed->bool('inplace'));
        self::assertTrue($parsed->bool('null-input'));
        self::assertTrue($parsed->bool('prettyPrint'));
    }

    public function testAnExplicitOutputFormatBeatsTheShortcut(): void
    {
        self::assertSame('csv', new ArgumentParser()->parse(['-j', '-o=csv'])->string('output-format'));
    }

    public function testFlagsMayFollowPositionals(): void
    {
        $parsed = new ArgumentParser()->parse(['test.yml', '-P', '.a', '-I=4']);

        self::assertSame(['test.yml', '.a'], $parsed->positionals);
        self::assertTrue($parsed->bool('prettyPrint'));
        self::assertSame(4, $parsed->int('indent'));
    }

    public function testDoubleDashEndsTheFlags(): void
    {
        $parsed = new ArgumentParser()->parse(['-P', '--', '-n', '--x']);

        self::assertTrue($parsed->bool('prettyPrint'));
        self::assertFalse($parsed->bool('null-input'));
        self::assertSame(['-n', '--x'], $parsed->positionals);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('errorProvider')]
    public function testUsageErrors(array $args, string $message): void
    {
        try {
            new ArgumentParser()->parse($args);
        } catch (UsageException $usageException) {
            self::assertStringContainsString($message, $usageException->getMessage());

            return;
        }

        self::fail('expected a UsageException');
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'unknown long'          => [['--nope'], 'unknown flag: --nope'];
        yield 'unknown short'         => [['-Z'], "unknown shorthand flag: 'Z' in -Z"];
        yield 'unknown short in cluster' => [['-nZ'], "unknown shorthand flag: 'Z' in -Z"];
        yield 'missing long value'    => [['--output-format'], 'flag needs an argument: --output-format'];
        yield 'missing short value'   => [['-o'], "flag needs an argument: 'o' in -o"];
        yield 'bad int'               => [['-I', 'x'], 'invalid argument "x" for "-I, --indent" flag: strconv.ParseInt: parsing "x": invalid syntax'];
        yield 'bad bool'              => [['--csv-auto-parse=maybe'], 'invalid argument "maybe" for "--csv-auto-parse" flag: strconv.ParseBool'];
    }
}
