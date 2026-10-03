<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliActionEnum;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Json\ColorScheme;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CliOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new CliOptions();

        self::assertSame(CliActionEnum::Run, $options->action);
        self::assertTrue($options->pretty);
        self::assertSame(2, $options->indent);
        self::assertFalse($options->isFlatPretty());
        self::assertSame(['ENV', '__prog_args', 'ARGS'], $options->globalNames());
    }

    public function testEncodeOptionsForPrettyOutput(): void
    {
        $encode = new CliOptions(ascii: true, sortKeys: true, indent: 4)->encodeOptions(null);

        self::assertSame(4, $encode->indent);
        self::assertFalse($encode->useTab);
        self::assertTrue($encode->sortKeys);
        self::assertTrue($encode->ascii);
        self::assertNull($encode->colors);
    }

    public function testEncodeOptionsForCompactOutput(): void
    {
        $encode = new CliOptions(pretty: false, indent: 3)->encodeOptions(null);

        self::assertSame(0, $encode->indent);
        self::assertFalse($encode->useTab);
    }

    public function testEncodeOptionsForTabs(): void
    {
        $encode = new CliOptions(tab: true)->encodeOptions(null);

        self::assertTrue($encode->useTab);
    }

    public function testFlatPrettyIsEncodedWithIndentOneForTheCallerToStrip(): void
    {
        $options = new CliOptions(indent: 0);

        self::assertTrue($options->isFlatPretty());
        self::assertSame(1, $options->encodeOptions(null)->indent);
    }

    public function testColorsArePassedThrough(): void
    {
        $scheme = ColorScheme::default();

        self::assertSame($scheme, new CliOptions()->encodeOptions($scheme)->colors);
    }

    public function testGlobalNamesAreUnique(): void
    {
        $options = new CliOptions(named: ['a' => '1', 'ENV' => 'x']);

        self::assertSame(['ENV', '__prog_args', 'ARGS', 'a'], $options->globalNames());
    }
}
