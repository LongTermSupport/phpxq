<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use Generator;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Whether a Node is deep-copied or shared is only right relative to later identity use: an enclosing
 * update (`..`, `|=`, `+=`) still holds the original descendants as matches, and a copy orphans them so
 * the update silently skips them. No static rule can judge that, so this is the net: seeded nested
 * documents run through whole-document updates, each compared with an independent PHP model of the
 * intended result.
 *
 * @internal
 */
#[CoversClass(ArithmeticOperator::class)]
final class SharedStructurePropertyTest extends TestCase
{
    private const int DOCUMENT_COUNT = 40;

    private const int MAX_DEPTH = 5;

    private const string IDENTITY_UPDATE = '.. |= .';

    private const string WRAP_UPDATE = '.. |= [] + .';

    private const string NESTED_SEQUENCES = '[[1],[2,[3]]]';

    private const string WRAP = 'wrap';

    #[DataProvider('prependToEverySequenceProvider')]
    #[DataProvider('appendToEverySequenceProvider')]
    #[DataProvider('addKeyToEveryMappingProvider')]
    #[DataProvider('identityProvider')]
    #[DataProvider('wrapEveryNodeProvider')]
    #[DataProvider('upcaseEveryStringProvider')]
    #[DataProvider('siblingPathsProvider')]
    #[DataProvider('historicScenarioProvider')]
    public function testUpdateReachesEveryDescendant(string $expression, string $input, string $expected): void
    {
        $result = new CliRunner()->run(['yq', '-o=json', '-I=0', $expression], $input);

        self::assertSame(0, $result->exitCode, $expression . ': ' . $result->stderr);
        self::assertSame($expected, trim($result->stdout), $expression . ' on ' . $input);
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function prependToEverySequenceProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            yield 'prepend ' . $name => [
                '(.. | select(tag == "!!seq")) |= [0] + .',
                self::encode($document),
                self::encode(self::prependEverySequence($document)),
            ];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function appendToEverySequenceProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            yield 'append ' . $name => [
                '(.. | select(tag == "!!seq")) += ["x"]',
                self::encode($document),
                self::encode(self::appendToEverySequence($document)),
            ];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function addKeyToEveryMappingProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            $input    = self::encode($document);
            $expected = self::encode(self::addKeyToEveryMapping($document));

            yield 'add key ' . $name . ' (+=)' => ['(.. | select(tag == "!!map")) += {"added": 1}', $input, $expected];

            yield 'add key ' . $name . ' (|= . +)' => ['(.. | select(tag == "!!map")) |= . + {"added": 1}', $input, $expected];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function identityProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            $json = self::encode($document);

            yield 'identity ' . $name => [self::IDENTITY_UPDATE, $json, $json];
        }

        yield 'anchor and alias' => [self::IDENTITY_UPDATE, "a: &x {b: [1, 2]}\nc: *x\n", '{"a":{"b":[1,2]},"c":{"b":[1,2]}}'];

        yield 'anchored sequence in a sequence' => [self::IDENTITY_UPDATE, "- &s [1, [2]]\n- *s\n- [*s]\n", '[[1,[2]],[1,[2]],[[1,[2]]]]'];

        yield 'anchored scalar' => [self::IDENTITY_UPDATE, "a: &v hello\nb: [*v, {c: *v}]\n", '{"a":"hello","b":["hello",{"c":"hello"}]}'];
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function wrapEveryNodeProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            yield 'wrap ' . $name => [
                self::WRAP_UPDATE,
                self::encode($document),
                self::encode(self::wrapEveryNode($document)),
            ];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function upcaseEveryStringProvider(): Generator
    {
        foreach (self::documents() as $name => $document) {
            yield 'upcase ' . $name => [
                '(.. | select(tag == "!!str")) |= upcase',
                self::encode($document),
                self::encode(self::upcaseEveryString($document)),
            ];
        }
    }

    /**
     * Sibling paths selected together, each holding nested sequences: the update must reach both
     * siblings and leave the unselected one alone.
     *
     * @return Generator<string, array{string, string, string}>
     */
    public static function siblingPathsProvider(): Generator
    {
        for ($seed = 1; $seed <= self::DOCUMENT_COUNT; ++$seed) {
            $state    = $seed * 7919;
            $siblings = [
                'a' => self::sequence($state, 3),
                'b' => self::sequence($state, 3),
                'c' => self::sequence($state, 3),
            ];
            $expected = $siblings;
            foreach (['a', 'b'] as $key) {
                $expected[$key][] = 1;
            }

            $name = \sprintf('siblings seed-%02d', $seed);

            yield $name . ' (append)' => ['(.a, .b) |= . + [1]', self::encode($siblings), self::encode($expected)];

            $expected = $siblings;
            foreach (['a', 'b'] as $key) {
                $expected[$key] = self::prependEverySequence($siblings[$key]);
            }

            yield $name . ' (descendants)' => ['(.a, .b | .. | select(tag == "!!seq")) |= [0] + .', self::encode($siblings), self::encode($expected)];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function historicScenarioProvider(): Generator
    {
        yield 'wrap every node, map nested in map' => [
            self::WRAP_UPDATE,
            '{"b":{"c":"x"}}',
            '[{"b":[{"c":["x"]}]}]',
        ];

        yield 'wrap every node, sequences only' => [
            self::WRAP_UPDATE,
            self::NESTED_SEQUENCES,
            '[[[1]],[[2],[[3]]]]',
        ];

        yield 'append to every sequence, nested sequences' => [
            '(.. | select(tag == "!!seq")) += ["x"]',
            self::NESTED_SEQUENCES,
            '[[1,"x"],[2,[3,"x"],"x"],"x"]',
        ];

        yield 'prepend to every sequence, nested sequences' => [
            '(.. | select(tag == "!!seq")) |= [0] + .',
            self::NESTED_SEQUENCES,
            '[0,[0,1],[0,2,[0,3]]]',
        ];

        yield 'add key to every mapping, nested mappings' => [
            '(.. | select(tag == "!!map")) += {"added": 1}',
            '{"a":{"b":{}}}',
            '{"a":{"b":{"added":1},"added":1},"added":1}',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function documents(): array
    {
        $documents = [];
        for ($seed = 1; $seed <= self::DOCUMENT_COUNT; ++$seed) {
            $state                                    = $seed * 7919;
            $documents[\sprintf('seed-%02d', $seed)]  = self::node($state, self::MAX_DEPTH);
        }

        return $documents;
    }

    private static function next(int &$state): int
    {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $state >> 8;
    }

    /**
     * Scalars, sequences, mappings and empty containers, nested up to the given depth.
     */
    private static function node(int &$state, int $depth): mixed
    {
        $pick = self::next($state) % 10;
        if (0 === $depth || $pick < 3) {
            $number = self::next($state);

            return 0 === $number % 2 ? $number % 100 : 's' . ($number % 100);
        }

        if ($pick < 6) {
            return self::sequence($state, $depth);
        }

        if ($pick < 9) {
            $mapping = [];
            $count   = self::next($state) % 4;
            for ($index = 0; $index < $count; ++$index) {
                $mapping['k' . $index] = self::node($state, $depth - 1);
            }

            return [] === $mapping ? new stdClass() : $mapping;
        }

        return 0 === self::next($state) % 2 ? [] : new stdClass();
    }

    /**
     * @return list<mixed>
     */
    private static function sequence(int &$state, int $depth): array
    {
        $items = [];
        $count = self::next($state) % 4;
        for ($index = 0; $index < $count; ++$index) {
            $items[] = self::node($state, $depth - 1);
        }

        return $items;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private static function isMapping(mixed $value): bool
    {
        return $value instanceof stdClass || (\is_array($value) && !array_is_list($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function children(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function prependEverySequence(mixed $value): mixed
    {
        return self::rewrite($value, 'prepend');
    }

    private static function appendToEverySequence(mixed $value): mixed
    {
        return self::rewrite($value, 'append');
    }

    private static function addKeyToEveryMapping(mixed $value): mixed
    {
        return self::rewrite($value, 'addKey');
    }

    private static function upcaseEveryString(mixed $value): mixed
    {
        return self::rewrite($value, 'upcase');
    }

    /**
     * `[] + node` is a one-element sequence for a scalar or a mapping and the node itself for a
     * sequence; `..` reaches every descendant of the original, so every one of them is rewritten.
     */
    private static function wrapEveryNode(mixed $value): mixed
    {
        return self::rewrite($value, self::WRAP);
    }

    /**
     * Applies one update to every node of the original document: the intended result of an update that
     * selects all nodes first and then rewrites each of them, whatever order the rewrites run in.
     */
    private static function rewrite(mixed $value, string $update): mixed
    {
        if (self::isMapping($value)) {
            $mapping = [];
            foreach (self::children($value) as $key => $child) {
                $mapping[$key] = self::rewrite($child, $update);
            }

            if ('addKey' === $update) {
                $mapping['added'] = 1;
            }

            $result = [] === $mapping ? new stdClass() : $mapping;

            return self::WRAP === $update ? [$result] : $result;
        }

        if (\is_array($value)) {
            $items = [];
            foreach ($value as $child) {
                $items[] = self::rewrite($child, $update);
            }

            return match ($update) {
                'prepend' => [0, ...$items],
                'append'  => [...$items, 'x'],
                default   => $items,
            };
        }

        return match ($update) {
            'upcase'     => \is_string($value) ? strtoupper($value) : $value,
            self::WRAP   => [$value],
            default      => $value,
        };
    }
}
