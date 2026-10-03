<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliActionEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CliActionTest extends TestCase
{
    #[DataProvider('provideActions')]
    public function testEveryActionIsListed(CliActionEnum $action, string $name): void
    {
        self::assertSame($name, $action->name);
        self::assertContains($action, CliActionEnum::cases());
    }

    /**
     * @return iterable<string, array{CliActionEnum, string}>
     */
    public static function provideActions(): iterable
    {
        yield 'run' => [CliActionEnum::Run, 'Run'];

        yield 'help' => [CliActionEnum::Help, 'Help'];

        yield 'version' => [CliActionEnum::Version, 'Version'];

        yield 'build configuration' => [CliActionEnum::BuildConfiguration, 'BuildConfiguration'];
    }
}
