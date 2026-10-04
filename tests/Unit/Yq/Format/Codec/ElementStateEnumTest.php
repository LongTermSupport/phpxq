<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\ElementStateEnum;
use LTS\PhpXq\Yq\Format\Codec\XmlElement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ElementStateEnum::class)]
final class ElementStateEnumTest extends TestCase
{
    public function testAnElementStartsInTheStartedState(): void
    {
        self::assertSame(ElementStateEnum::Started, new XmlElement()->state);
    }
}
