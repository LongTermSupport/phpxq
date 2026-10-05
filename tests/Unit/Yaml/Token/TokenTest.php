<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Token\Token;
use LTS\PhpXq\Yaml\Token\TokenTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenTest extends TestCase
{
    public function testDefaultsPointAtTheFirstCharacterOfTheFirstLine(): void
    {
        $token = new Token(TokenTypeEnum::Scalar);

        self::assertSame('', $token->value);
        self::assertSame(1, $token->line);
        self::assertSame(1, $token->column);
        self::assertSame(NodeStyleEnum::Default, $token->style);
    }

    public function testExplicitValuesAreKept(): void
    {
        $token = new Token(TokenTypeEnum::Scalar, 'v', 3, 7, NodeStyleEnum::Literal);

        self::assertSame(TokenTypeEnum::Scalar, $token->type);
        self::assertSame('v', $token->value);
        self::assertSame(3, $token->line);
        self::assertSame(7, $token->column);
        self::assertSame(NodeStyleEnum::Literal, $token->style);
    }
}
