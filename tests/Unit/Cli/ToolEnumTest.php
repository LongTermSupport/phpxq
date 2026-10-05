<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use LTS\PhpXq\Cli\ToolEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ToolEnumTest extends TestCase
{
    public function testBackingValuesAreTheCommandNames(): void
    {
        self::assertSame(ToolEnum::Yq, ToolEnum::tryFrom($this->nameOf(ToolEnum::Yq)));
        self::assertNull(ToolEnum::tryFrom($this->nameOf(ToolEnum::Jq) . 'x'));
    }

    public function testUsageNamesEveryTool(): void
    {
        self::assertSame("usage: phpxq jq|yq [arguments...]\n", ToolEnum::usage());
    }

    public function testVersionLinesNameTheCompatibleUpstreamReleases(): void
    {
        self::assertSame("jq-1.8.2 compatible (jq)\n", ToolEnum::Jq->versionLine());
        self::assertSame("yq v4.54.1 compatible (yq)\n", ToolEnum::Yq->versionLine());
    }

    #[DataProvider('provideProgramNames')]
    public function testFromProgramName(string $argv0, ?ToolEnum $expected): void
    {
        self::assertSame($expected, ToolEnum::fromProgramName($argv0));
    }

    /**
     * @return iterable<string, array{string, ?ToolEnum}>
     */
    public static function provideProgramNames(): iterable
    {
        yield 'plain jq' => ['jq', ToolEnum::Jq];
        yield 'path' => ['/usr/local/bin/yq', ToolEnum::Yq];
        yield 'windows exe' => ['C:\bin\JQ.exe', ToolEnum::Jq];
        yield 'umbrella name' => ['phpxq', null];
        yield 'phar' => ['phpxq.phar', null];
    }

    private function nameOf(ToolEnum $tool): string
    {
        return $tool->value;
    }
}
