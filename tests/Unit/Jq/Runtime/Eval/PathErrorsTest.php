<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\PathErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathErrors::class)]
final class PathErrorsTest extends TestCase
{
    public function testMessages(): void
    {
        self::assertSame(
            'Invalid path expression near attempt to access element "c" of [{"b":0}]',
            PathErrors::access('c', [new \LTS\PhpXq\Json\JsonObject(['b' => 0])])->getMessage(),
        );
        self::assertSame(
            'Invalid path expression near attempt to iterate through [1]',
            PathErrors::iterate([1])->getMessage(),
        );
        self::assertSame('Invalid path expression with result [2,1,0]', PathErrors::result([2, 1, 0])->getMessage());
    }
}
