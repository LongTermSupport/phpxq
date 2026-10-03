<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Builtin\Core\StreamFunction;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\ClosureFilter;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StreamFunctionTest extends TestCase
{
    public function testItExposesNameAndArity(): void
    {
        $function = new StreamFunction('twice', 0, static function (): void {
        });

        self::assertSame('twice', $function->name());
        self::assertSame(0, $function->arity());
    }

    public function testItRunsTheClosureAndPassesTheEmitter(): void
    {
        $function = new StreamFunction('twice', 1, static function (RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void {
            $emit($input);
            $emit(\count($args));
        });
        $out = [];

        $function->run(new FakeContext(), 'x', [ClosureFilter::identity()], static function (mixed $value) use (&$out): void {
            $out[] = $value;
        });

        self::assertSame(['x', 1], $out);
    }
}
