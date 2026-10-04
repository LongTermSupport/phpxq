<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\SimpleKindEnum;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimpleKindEnum::class)]
final class SimpleKindEnumTest extends TestCase
{
    public function testEachSimpleValueHasItsKind(): void
    {
        self::assertSame(SimpleKindEnum::Text, SimpleKindEnum::of('a'));
        self::assertSame(SimpleKindEnum::Sequence, SimpleKindEnum::of([1]));
        self::assertSame(SimpleKindEnum::Mapping, SimpleKindEnum::of(JsonObject::fromPairs(['a' => 1])));
        self::assertSame(SimpleKindEnum::Number, SimpleKindEnum::of(1));
        self::assertSame(SimpleKindEnum::Number, SimpleKindEnum::of(1.5));
    }

    public function testAnythingElseIsOther(): void
    {
        self::assertSame(SimpleKindEnum::Other, SimpleKindEnum::of(true));
    }
}
