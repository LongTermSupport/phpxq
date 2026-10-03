<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FormatOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(FormatOp::class)]
final class FormatOpTest extends OpTestCase
{
    public function testAppliesTheFormatToTheInput(): void
    {
        $op = new FormatOp(static fn (mixed $value): string => base64_encode(\is_string($value) ? $value : ''));

        self::assertSame(['aGk='], self::outputs($op, 'hi'));
        self::assertSame('aGk=', $op->value(null, 'hi'));
    }
}
