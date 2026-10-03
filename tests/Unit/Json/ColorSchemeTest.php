<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\ColorScheme;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ColorSchemeTest extends TestCase
{
    public function testDefaultPaletteUsesEscapeSequences(): void
    {
        $scheme = ColorScheme::default();

        foreach ([$scheme->null, $scheme->false, $scheme->true, $scheme->number, $scheme->string, $scheme->array, $scheme->object, $scheme->objectKey] as $sequence) {
            self::assertStringStartsWith("\e[", $sequence);
            self::assertStringEndsWith('m', $sequence);
        }
    }
}
