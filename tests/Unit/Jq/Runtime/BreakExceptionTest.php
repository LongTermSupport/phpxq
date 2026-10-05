<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\BreakException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
final class BreakExceptionTest extends TestCase
{
    public function testLabelIsComparedByIdentity(): void
    {
        $label = new stdClass();
        $break = new BreakException($label);

        self::assertSame($label, $break->label);
        self::assertNotSame(new stdClass(), $break->label);
    }
}
