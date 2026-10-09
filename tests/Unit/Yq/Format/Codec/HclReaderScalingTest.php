<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Tests\Support\GrowthProbe;
use LTS\PhpXq\Yq\Format\Codec\HclDecoder;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Reading HCL with many attributes or block labels in one body stays linear in their number.
 *
 * @internal
 */
#[Medium]
final class HclReaderScalingTest extends TestCase
{
    public function testManyAttributesScaleLinearly(): void
    {
        $growth = GrowthProbe::growth(static function (int $attributes): void {
            $hcl = '';
            for ($i = 0; $i < $attributes; ++$i) {
                $hcl .= 'k' . $i . ' = ' . $i . "\n";
            }

            self::read($hcl);
        }, 1500);

        self::assertLessThan(GrowthProbe::LINEAR_CEILING, $growth);
    }

    public function testManyBlockLabelsScaleLinearly(): void
    {
        $growth = GrowthProbe::growth(static function (int $blocks): void {
            $hcl = '';
            for ($i = 0; $i < $blocks; ++$i) {
                $hcl .= 'resource "l' . $i . "\" {\n  v = " . $i . "\n}\n";
            }

            self::read($hcl);
        }, 1500);

        self::assertLessThan(GrowthProbe::LINEAR_CEILING, $growth);
    }

    private static function read(string $hcl): void
    {
        self::assertCount(1, iterator_to_array(new HclDecoder()->decode($hcl, new FormatOptions()), false));
    }
}
