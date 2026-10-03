<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\TranslatedPattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TranslatedPatternTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $pattern = new TranslatedPattern('a(b)', [null], true);

        self::assertSame('a(b)', $pattern->pcre);
        self::assertSame([null], $pattern->groupNames);
        self::assertTrue($pattern->usesWordEscapes);
    }
}
