<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\Token\ScanToken;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ScanTokenTest extends TestCase
{
    public function testConstructorStoresPositions(): void
    {
        $token = new ScanToken(ScanToken::SCALAR, 3, 1, 3, 1, 5, 'ab', '', ScanToken::DOUBLE);

        self::assertSame(ScanToken::SCALAR, $token->type);
        self::assertSame(3, $token->startIndex);
        self::assertSame(5, $token->endColumn);
        self::assertSame('ab', $token->value);
        self::assertSame(ScanToken::DOUBLE, $token->style);
    }
}
