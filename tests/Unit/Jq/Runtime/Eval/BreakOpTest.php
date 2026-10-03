<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\Eval\BreakOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(BreakOp::class)]
final class BreakOpTest extends OpTestCase
{
    public function testThrowsTheTokenAtTheGivenDepth(): void
    {
        $token = new stdClass();
        $env   = new Env(new Env(null, $token), 'other');

        try {
            self::outputs(new BreakOp(1), null, $env);
            self::fail('expected a BreakException');
        } catch (BreakException $breakException) {
            self::assertSame($token, $breakException->label);
        }
    }

    public function testPathModeThrowsToo(): void
    {
        $token = new stdClass();

        try {
            self::pathOutputs(new BreakOp(0), null, [], new Env(null, $token));
            self::fail('expected a BreakException');
        } catch (BreakException $breakException) {
            self::assertSame($token, $breakException->label);
        }
    }
}
