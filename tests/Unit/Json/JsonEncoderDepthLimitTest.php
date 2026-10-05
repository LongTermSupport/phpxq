<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\ColorScheme;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * The coloured printer stops at the same depth jq does. Each case builds a value ten thousand levels deep,
 * which takes longer than the limit for a small test.
 *
 * @internal
 */
#[Large]
final class JsonEncoderDepthLimitTest extends TestCase
{
    private const string SKIPPED = '<skipped: too deep>';

    #[DataProvider('depthProvider')]
    public function testColouredArraysSkipBeyondTheDepthLimit(int $levels, bool $skipped): void
    {
        $value = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $value = [$value];
        }

        $text = new JsonEncoder()->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        self::assertSame($skipped, str_contains($text, self::SKIPPED));
    }

    #[DataProvider('depthProvider')]
    public function testColouredObjectsSkipBeyondTheDepthLimit(int $levels, bool $skipped): void
    {
        $value = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $value = JsonObject::fromPairs(['a' => $value]);
        }

        $text = new JsonEncoder()->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        self::assertSame($skipped, str_contains($text, self::SKIPPED));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function depthProvider(): iterable
    {
        yield 'at the limit' => [10000, false];
        yield 'one beyond'   => [10001, true];
    }
}
