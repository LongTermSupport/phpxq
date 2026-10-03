<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\FrontMatterSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FrontMatterSplitterTest extends TestCase
{
    #[DataProvider('splitProvider')]
    public function testSplit(string $input, string $yaml, string $content): void
    {
        self::assertSame([$yaml, $content], new FrontMatterSplitter()->split($input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function splitProvider(): iterable
    {
        yield 'leading separator belongs to the yaml' => ["---\na: 1\n---\ntext\n", "---\na: 1\n", "---\ntext\n"];
        yield 'no leading separator'                  => ["a: 1\n---\ntext\n", "a: 1\n", "---\ntext\n"];
        yield 'no content'                            => ["---\na: 1\n", "---\na: 1\n", ''];
        yield 'plain yaml'                            => ["a: 1\n", "a: 1\n", ''];
        yield 'empty'                                 => ['', '', ''];
        yield 'content keeps further separators'      => ["a: 1\n---\nx\n---\ny\n", "a: 1\n", "---\nx\n---\ny\n"];
    }
}
