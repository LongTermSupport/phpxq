<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\StringInterpolation;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StringInterpolationTest extends TestCase
{
    public function testMixesTextAndExpressionParts(): void
    {
        $expr = new Identity();
        $node = new StringInterpolation('base64', ['x', $expr]);

        self::assertSame('base64', $node->format);
        self::assertSame(['x', $expr], $node->parts);
    }
}
