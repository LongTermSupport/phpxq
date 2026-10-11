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
    private const string FIXED_MERGE = '--yaml-fix-merge-anchor-to-spec';

    /**
     * With `--yaml-fix-merge-anchor-to-spec` every form of merge value is honoured, by navigation and encoders alike.
     */
    #[DataProvider('documents')]
    public function testSpecMergesAreTheSameForNavigationAndTheEncoders(string $document): void
    {
        self::assertSame([0, "1\n", ''], $this->invoke($document, self::FIXED_MERGE, '.b.a'));
        self::assertSame([0, "{\"a\":1,\"c\":2}\n", ''], $this->invoke($document, self::FIXED_MERGE, '-o=json', '-I=0', '.b'));
        self::assertSame([0, "a = 1\nc = 2\n", ''], $this->invoke($document, self::FIXED_MERGE, '-o=props', '.b'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        foreach (self::mergeValues() as $name => [$document]) {
            yield $name => [$document];
        }
    }

    /**
     * The reference's default (legacy) encoders merge only aliases of mappings: an inline mapping is dropped and an
     * alias of anything else is an error. Navigation honours every form in both modes.
     */
    #[DataProvider('mergeValues')]
    public function testLegacyEncodersMergeOnlyAliasesOfMappings(string $document, string $json): void
    {
        self::assertSame([0, "1\n", ''], $this->invoke($document, '.b.a'));
        if ('' !== $json) {
            self::assertSame([0, $json, ''], $this->invoke($document, '-o=json', '-I=0', '.b'));

            return;
        }

        self::assertSame([1, '', "Error: can only use merge anchors with maps (!!map) or sequences (!!seq) of maps, but got sequence containing !!seq\n"], $this->invoke($document, '-o=json', '-I=0', '.b'));
    }

    /**
     * @return iterable<string, array{string, string}> the document, and what the legacy JSON encoder writes for `.b`
     *                                                 ('' when it is an error)
     */
    public static function mergeValues(): iterable
    {
        yield 'alias' => ["x: &x {a: 1}\nb: {<<: *x, c: 2}\n", "{\"a\":1,\"c\":2}\n"];
        yield 'inline mapping' => ["b: {<<: {a: 1}, c: 2}\n", "{\"c\":2}\n"];
        yield 'sequence of aliases' => ["x: &x {a: 1}\nb: {<<: [*x], c: 2}\n", "{\"a\":1,\"c\":2}\n"];
        yield 'sequence with an inline mapping' => ["b: {<<: [{a: 1}], c: 2}\n", "{\"c\":2}\n"];
        yield 'alias of a sequence' => ["x: &x [{a: 1}]\nb: {<<: *x, c: 2}\n", ''];
    }

    public function testLegacyEncodersLetTheOwnKeyWinOverAnInlineMergeTheyDrop(): void
    {
        self::assertSame([0, "{\"a\":{\"y\":3}}\n", ''], $this->invoke("a: {<<: {x: 1, y: 2}, y: 3}\n", '-o=json', '-I=0', '.'));
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
