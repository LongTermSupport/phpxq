<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\CommandEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CommandEnum::class)]
final class CommandEnumTest extends TestCase
{
    #[DataProvider('aliases')]
    public function testAnAliasResolvesToItsCommand(CommandEnum $alias, CommandEnum $expected): void
    {
        self::assertSame($expected, $alias->canonical());
    }

    /**
     * @return iterable<string, array{CommandEnum, CommandEnum}>
     */
    public static function aliases(): iterable
    {
        yield 'eval short' => [CommandEnum::EvalShort, CommandEnum::Eval];

        yield 'eval-all short' => [CommandEnum::EvalAllShort, CommandEnum::EvalAll];

        yield 'a command is its own canonical form' => [CommandEnum::Help, CommandEnum::Help];
    }

    #[DataProvider('typedWords')]
    public function testTheValueIsTheWordTypedOnTheCommandLine(string $word, ?CommandEnum $expected): void
    {
        self::assertSame($expected, CommandEnum::tryFrom($word));
    }

    /**
     * @return iterable<string, array{string, ?CommandEnum}>
     */
    public static function typedWords(): iterable
    {
        yield 'ea' => ['ea', CommandEnum::EvalAllShort];

        yield '__completeNoDesc' => ['__completeNoDesc', CommandEnum::CompleteNoDescriptions];

        yield 'unknown' => ['nope', null];
    }

    #[DataProvider('completionRequests')]
    public function testOnlyTheTwoHiddenCommandsAreCompletionRequests(CommandEnum $command, bool $expected): void
    {
        self::assertSame($expected, $command->isCompletionRequest());
    }

    /**
     * @return iterable<string, array{CommandEnum, bool}>
     */
    public static function completionRequests(): iterable
    {
        yield 'complete' => [CommandEnum::Complete, true];

        yield 'complete without descriptions' => [CommandEnum::CompleteNoDescriptions, true];

        yield 'completion script' => [CommandEnum::Completion, false];
    }
}
