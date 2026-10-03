<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Emitter;

use LTS\PhpXq\Yaml\Emitter\ScalarAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ScalarAnalysisTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool, bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        // value, flowPlain, blockPlain, singleQuoted, blockAllowed, multiline
        yield 'empty'                 => ['', false, true, true, false, false];

        yield 'word'                  => ['abc', true, true, true, true, false];

        yield 'words with space'      => ['a b', true, true, true, true, false];

        yield 'colon then space'      => ['a: b', false, false, true, true, false];

        yield 'colon inside'          => ['a:b', false, true, true, true, false];

        yield 'comma'                 => ['a,b', false, true, true, true, false];

        yield 'hash after space'      => ['a #b', false, false, true, true, false];

        yield 'hash inside word'      => ['a#b', true, true, true, true, false];

        yield 'leading dash space'    => ['- a', false, false, true, true, false];

        yield 'leading dash word'     => ['-a', true, true, true, true, false];

        yield 'leading question'      => ['?a', false, true, true, true, false];

        yield 'leading question sp'   => ['? a', false, false, true, true, false];

        yield 'document marker'       => ['---', false, false, true, true, false];

        yield 'leading space'         => [' a', false, false, true, true, false];

        yield 'trailing space'        => ['a ', false, false, true, false, false];

        yield 'newline'               => ["a\nb", false, false, true, true, true];

        yield 'space before newline'  => ["a \nb", false, false, false, false, true];

        yield 'newline before space'  => ["a\n b", false, false, false, true, true];

        yield 'tab is special'        => ["a\tb", false, false, false, false, false];

        yield 'astral is special'     => ["a\u{1F600}", false, false, false, false, false];

        yield 'bmp is fine'           => ["caf\u{e9}", true, true, true, true, false];

        yield 'flow brackets'         => ['a[b', false, true, true, true, false];
    }

    #[DataProvider('cases')]
    public function testFlags(string $value, bool $flowPlain, bool $blockPlain, bool $single, bool $block, bool $multiline): void
    {
        $analysis = ScalarAnalysis::of($value);

        self::assertSame($flowPlain, $analysis->flowPlainAllowed, 'flowPlain');
        self::assertSame($blockPlain, $analysis->blockPlainAllowed, 'blockPlain');
        self::assertSame($single, $analysis->singleQuotedAllowed, 'single');
        self::assertSame($block, $analysis->blockAllowed, 'block');
        self::assertSame($multiline, $analysis->multiline, 'multiline');
    }
}
