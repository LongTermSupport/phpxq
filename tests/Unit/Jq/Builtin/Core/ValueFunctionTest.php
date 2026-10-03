<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\ValueFunction;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ValueFunctionTest extends TestCase
{
    public function testItExposesNameAndArity(): void
    {
        $function = new ValueFunction('plus', 1, static fn (): mixed => null);

        self::assertSame('plus', $function->name());
        self::assertSame(1, $function->arity());
    }

    public function testItCallsTheClosureWithContextInputAndArguments(): void
    {
        $context  = new FakeContext();
        $function = new ValueFunction('plus', 1, static fn (RuntimeContextInterface $c, mixed $input, array $args): mixed => [$c, $input, $args]);

        self::assertSame([$context, 5, [7]], $function->call($context, 5, [7]));
    }
}
