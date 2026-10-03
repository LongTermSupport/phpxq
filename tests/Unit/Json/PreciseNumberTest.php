<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PreciseNumberTest extends TestCase
{
    public function testCarriesLiteralAndDouble(): void
    {
        $number = new PreciseNumber(13911860366432392.0, '13911860366432393');

        self::assertSame('13911860366432393', $number->literal);
        self::assertSame(13911860366432392.0, $number->value);
    }
}
