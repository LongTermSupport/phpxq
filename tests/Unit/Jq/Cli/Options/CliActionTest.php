<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliAction;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CliActionTest extends TestCase
{
    public function testEveryActionIsDistinct(): void
    {
        self::assertCount(4, CliAction::cases());
        self::assertNotSame(CliAction::Help, CliAction::Version);
        self::assertNotSame(CliAction::Run, CliAction::BuildConfiguration);
    }
}
