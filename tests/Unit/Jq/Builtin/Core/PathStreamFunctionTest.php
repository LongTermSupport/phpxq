<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Builtin\Core\PathStreamFunction;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PathStreamFunctionTest extends TestCase
{
    public function testItExposesNameAndArity(): void
    {
        $function = new PathStreamFunction('f', 2, static function (): void {
        }, static function (): void {
        });

        self::assertSame('f', $function->name());
        self::assertSame(2, $function->arity());
    }

    public function testValueModeUsesTheFirstClosure(): void
    {
        $function = new PathStreamFunction(
            'f',
            0,
            static function (RuntimeContext $c, mixed $input, array $args, Closure $emit): void {
                $emit($input);
            },
            static function (): never {
                self::fail('path mode must not run');
            },
        );
        $out = [];

        $function->run(new FakeContext(), 4, [], static function (mixed $value) use (&$out): void {
            $out[] = $value;
        });

        self::assertSame([4], $out);
    }

    public function testPathModeUsesTheSecondClosure(): void
    {
        $function = new PathStreamFunction(
            'f',
            0,
            static function (): never {
                self::fail('value mode must not run');
            },
            static function (RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void {
                $emit($path, $input);
            },
        );
        $out = [];

        $function->runPaths(new FakeContext(), ['a'], 4, [], static function (?array $path, mixed $value) use (&$out): void {
            $out[] = [$path, $value];
        });

        self::assertSame([[['a'], 4]], $out);
    }
}
