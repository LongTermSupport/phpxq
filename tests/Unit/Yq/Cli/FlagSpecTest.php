<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\FlagSpec;
use LTS\PhpXq\Yq\Cli\FlagTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FlagSpecTest extends TestCase
{
    public function testLabelWithShortName(): void
    {
        $spec = new FlagSpec('indent', 'I', FlagTypeEnum::Int, 2, 'sets indent level for output');

        self::assertSame('-I, --indent', $spec->label());
    }

    public function testLabelWithoutShortName(): void
    {
        $spec = new FlagSpec('csv-separator', '', FlagTypeEnum::String, ',', 'CSV Separator character');

        self::assertSame('--csv-separator', $spec->label());
    }
}
