<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\EncodeOptions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EncodeOptionsTest extends TestCase
{
    public function testDefaultsMatchJq(): void
    {
        $options = new EncodeOptions();

        self::assertSame(2, $options->indent);
        self::assertFalse($options->useTab);
        self::assertFalse($options->sortKeys);
        self::assertFalse($options->ascii);
        self::assertNull($options->colors);
    }

    public function testCompactHasNoIndent(): void
    {
        self::assertSame(0, EncodeOptions::compact()->indent);
    }
}
