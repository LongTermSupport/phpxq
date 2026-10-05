<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\SourceBindings;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SourceBindings::class)]
final class SourceBindingsTest extends OpTestCase
{
    public function testContinuationRunsOncePerSourceOutputWithTheItemBound(): void
    {
        $seen = [];
        SourceBindings::each(
            self::generator('a', 'b'),
            new VarBinder(),
            null,
            'input',
            static function (?Env $bound) use (&$seen): void {
                $seen[] = $bound?->value;
            },
        );

        self::assertSame(['a', 'b'], $seen);
    }

    public function testEmptySourceNeverContinues(): void
    {
        $calls = 0;
        SourceBindings::each(self::generator(), new VarBinder(), null, null, static function () use (&$calls): void {
            ++$calls;
        });

        self::assertSame(0, $calls);
    }
}
