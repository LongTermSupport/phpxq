<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Label;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LabelTest extends TestCase
{
    public function testCarriesNameAndBody(): void
    {
        $body  = new Identity();
        $label = new Label('out', $body);

        self::assertSame('out', $label->name);
        self::assertSame($body, $label->body);
    }
}
