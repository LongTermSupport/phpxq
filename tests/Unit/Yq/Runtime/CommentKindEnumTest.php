<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Yq\Runtime\CommentKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CommentKindEnum::class)]
final class CommentKindEnumTest extends TestCase
{
    #[DataProvider('spellings')]
    public function testBackingValues(string $spelling, CommentKindEnum $expected): void
    {
        self::assertSame($expected, CommentKindEnum::tryFrom($spelling));
    }

    /**
     * @return iterable<string, array{string, CommentKindEnum}>
     */
    public static function spellings(): iterable
    {
        yield 'head' => ['head', CommentKindEnum::Head];

        yield 'line' => ['line', CommentKindEnum::Line];

        yield 'foot' => ['foot', CommentKindEnum::Foot];

        yield 'all' => ['all', CommentKindEnum::All];
    }
}
