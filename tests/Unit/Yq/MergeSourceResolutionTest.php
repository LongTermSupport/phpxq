<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yaml\MergeSources;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Runtime\Traversal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Navigation and the encoders resolve what a `<<` value merges in the same way: an alias of a mapping, an inline
 * mapping, or a sequence of either. A key that `.key` finds must also be written by `-o json`.
 *
 * @internal
 */
#[CoversClass(MergeSources::class)]
#[CoversClass(NodeTools::class)]
#[CoversClass(Traversal::class)]
#[Medium]
final class MergeSourceResolutionTest extends TestCase
{
    #[DataProvider('mergeValues')]
    public function testNavigationAndTheEncodersSeeTheSameMergedKeys(string $document): void
    {
        self::assertSame([0, "1\n", ''], $this->invoke($document, '.b.a'));
        self::assertSame([0, "{\"a\":1,\"c\":2}\n", ''], $this->invoke($document, '-o=json', '-I=0', '.b'));
        self::assertSame([0, "a = 1\nc = 2\n", ''], $this->invoke($document, '-o=props', '.b'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mergeValues(): iterable
    {
        yield 'alias' => ["x: &x {a: 1}\nb: {<<: *x, c: 2}\n"];
        yield 'inline mapping' => ["b: {<<: {a: 1}, c: 2}\n"];
        yield 'sequence of aliases' => ["x: &x {a: 1}\nb: {<<: [*x], c: 2}\n"];
        yield 'sequence with an inline mapping' => ["b: {<<: [{a: 1}], c: 2}\n"];
        yield 'alias of a sequence' => ["x: &x [{a: 1}]\nb: {<<: *x, c: 2}\n"];
    }

    /**
     * @return array{int, string, string}
     */
    private function invoke(string $stdin, string ...$arguments): array
    {
        $result = new CliRunner()->run(['yq', ...array_values($arguments)], $stdin);

        return [$result->exitCode, $result->stdout, $result->stderr];
    }
}
