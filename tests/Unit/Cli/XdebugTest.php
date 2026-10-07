<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use LTS\PhpXq\Cli\Xdebug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Xdebug::class)]
final class XdebugTest extends TestCase
{
    #[DataProvider('modes')]
    public function testAModeIsActiveOnlyWhenTheExtensionIsLoadedAndTheModeIsNotOff(bool $loaded, string $mode, bool $expected): void
    {
        self::assertSame($expected, Xdebug::isActive($loaded, $mode));
    }

    /**
     * @return iterable<string, array{bool, string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'not loaded'                => [false, 'coverage', false];
        yield 'loaded with coverage'      => [true, 'coverage', true];
        yield 'loaded with several modes' => [true, 'develop,debug', true];
        yield 'loaded but off'            => [true, 'off', false];
        yield 'loaded with an empty mode' => [true, '', false];
        yield 'loaded, off in any case'   => [true, 'OFF', false];
        yield 'loaded, padded off'        => [true, ' off ', false];
    }

    #[DataProvider('restartDecisions')]
    public function testRestartHappensOnlyForAnActiveXdebugThatIsNotAllowedAndCanBeReplaced(bool $active, string $allowed, bool $canExec, bool $expected): void
    {
        self::assertSame($expected, Xdebug::shouldRestart($active, $allowed, $canExec));
    }

    /**
     * @return iterable<string, array{bool, string, bool, bool}>
     */
    public static function restartDecisions(): iterable
    {
        yield 'active, not allowed'       => [true, '', true, true];
        yield 'inactive'                  => [false, '', true, false];
        yield 'active but allowed'        => [true, '1', true, false];
        yield 'active, cannot replace'    => [true, '', false, false];
        yield 'allowed with a zero value' => [true, '0', true, true];
    }

    public function testTheEnvironmentForTheRestartSwitchesXdebugOff(): void
    {
        $environment = Xdebug::restartEnvironment(['PATH' => '/bin', 'XDEBUG_MODE' => 'coverage']);

        self::assertSame(['PATH' => '/bin', 'XDEBUG_MODE' => 'off'], $environment);
    }

    public function testTheCurrentProcessAgreesWithItsOwnEnvironment(): void
    {
        $mode = getenv('XDEBUG_MODE');
        $mode = \is_string($mode) && '' !== $mode ? $mode : (string)\ini_get('xdebug.mode');

        self::assertSame(Xdebug::isActive(\extension_loaded('xdebug'), $mode), Xdebug::active());
    }
}
