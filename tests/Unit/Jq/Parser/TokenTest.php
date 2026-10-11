<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\Token;
use LTS\PhpXq\Jq\Parser\TokenTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenTest extends TestCase
{
    public function testCarriesTypeTextAndPosition(): void
    {
        $token = new Token(TokenTypeEnum::Field, 'foo', 2, 5);

        self::assertSame('foo', $token->text);
        self::assertSame(2, $token->line);
        self::assertSame(5, $token->column);
        self::assertSame(TokenTypeEnum::Field, $token->type);
    }
}
