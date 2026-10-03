<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\Token;
use LTS\PhpXq\Jq\Parser\TokenType;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenTest extends TestCase
{
    public function testCarriesTypeTextAndPosition(): void
    {
        $token = new Token(TokenType::Field, 'foo', 2, 5);

        self::assertSame('foo', $token->text);
        self::assertSame(2, $token->line);
        self::assertSame(5, $token->column);
        self::assertTrue($token->is(TokenType::Field));
        self::assertFalse($token->is(TokenType::Ident));
    }
}
