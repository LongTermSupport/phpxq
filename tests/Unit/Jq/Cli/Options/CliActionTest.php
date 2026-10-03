<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CliActionTest extends TestCase
{
    #[DataProvider('provideActions')]
    public function testEveryActionIsListed(CliAction $action, string $name): void
    {
        self::assertSame($name, $action->name);
        self::assertContains($action, CliAction::cases());
    }

    /**
     * @return iterable<string, array{CliAction, string}>
     */
    public static function provideActions(): iterable
    {
        yield 'run' => [CliAction::Run, 'Run'];

        yield 'help' => [CliAction::Help, 'Help'];

        yield 'version' => [CliAction::Version, 'Version'];

        yield 'build configuration' => [CliAction::BuildConfiguration, 'BuildConfiguration'];
    }
}
