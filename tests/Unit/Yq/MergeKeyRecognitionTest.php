<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yaml\MergeKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A `<<` key that is quoted or carries another tag is a merge key for some uses and not others, exactly as the
 * reference (yq 4.54) decides: navigation by the `!!merge` tag, legacy output by the text `<<`, spec-fixed output
 * by both. Every expected output here is what the reference printed for the same input and arguments.
 *
 * @internal
 */
#[CoversClass(MergeKey::class)]
#[Medium]
final class MergeKeyRecognitionTest extends TestCase
{
    private const string FIXED_MERGE = '--yaml-fix-merge-anchor-to-spec';

    private const string JSON = '-o=json';

    private const string COMPACT = '-I=0';

    private const string EXPLODE = 'explode(.)';

    private const string LOOKUP = '.b.x';

    private const string MERGED_JSON = "{\"a\":{\"x\":1},\"b\":{\"x\":1,\"y\":2}}\n";

    private const string UNMERGED_JSON = "{\"a\":{\"x\":1},\"b\":{\"<<\":{\"x\":1},\"y\":2}}\n";

    private const string NULL = "null\n";

    private const string CUSTOM_TAGGED = '!x <<';

    private const string QUOTED = '"<<"';

    private const string MERGE_TAGGED_NAME = '!!merge foo';

    private const string BOTH_KEYS = "a: &a {x: 1}\nb: &b {y: 3}\nm: {<<: *a, !!merge foo: *b, z: 2}\n";

    private const string OVERRIDDEN = "m: {!!merge foo: {x: 5}, x: 7}\n";

    private const string SHARED_KEY = "a: &a {x: 1, k: a}\nb: &b {y: 3, k: b}\nm: {<<: *a, !!merge foo: *b, z: 2}\n";

    private const string ITERATE = '[.m[]]';

    private const string TO_ENTRIES = '.m | to_entries';

    private const string WITH_ENTRIES = '.m | with_entries(.)';

    private const string BOTH_KEYS_LISTED = '{"x":1,"foo":{"y":3},"z":2}' . "\n";

    #[DataProvider('cases')]
    public function testEachUseRecognisesMergeKeysAsTheReferenceDoes(string $key, string $expected, string ...$arguments): void
    {
        $document = \sprintf("a: &a {x: 1}\nb: {%s: *a, y: 2}\n", $key);

        self::assertSame([0, $expected, ''], $this->invoke($document, ...$arguments));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function cases(): iterable
    {
        foreach ([self::CUSTOM_TAGGED, '!!binary <<', '!!int <<', self::QUOTED, '!!str <<'] as $key) {
            yield $key . ', legacy json' => [$key, self::MERGED_JSON, self::JSON, self::COMPACT, '.'];

            yield $key . ', spec-fixed json' => [$key, self::UNMERGED_JSON, self::FIXED_MERGE, self::JSON, self::COMPACT, '.'];

            yield $key . ', lookup' => [$key, self::NULL, self::LOOKUP];
        }

        yield '!x <<, legacy format operator' => [self::CUSTOM_TAGGED, self::MERGED_JSON, '@json'];

        yield '!x <<, legacy props' => [self::CUSTOM_TAGGED, "a.x = 1\nb.x = 1\nb.y = 2\n", '-o=props', '.'];

        yield '"<<", legacy xml' => [self::QUOTED, "<a>\n  <x>1</x>\n</a>\n<b>\n  <x>1</x>\n  <y>2</y>\n</b>\n", '-o=xml', '.'];

        yield '"<<", legacy explode' => [self::QUOTED, "a: {x: 1}\nb: {x: 1, y: 2}\n", self::EXPLODE];

        yield '!x <<, legacy explode' => [self::CUSTOM_TAGGED, "a: {x: 1}\nb: {x: 1, y: 2}\n", self::EXPLODE];

        yield '"<<", spec-fixed explode' => [self::QUOTED, "a: {x: 1}\nb: {\"<<\": {x: 1}, y: 2}\n", self::FIXED_MERGE, self::EXPLODE];

        yield '!x <<, spec-fixed explode' => [self::CUSTOM_TAGGED, "a: {x: 1}\nb: {!x <<: {x: 1}, y: 2}\n", self::FIXED_MERGE, self::EXPLODE];

        yield '!!merge on another name, lookup' => [self::MERGE_TAGGED_NAME, "1\n", self::LOOKUP];

        yield '!!merge on another name, legacy json' => [self::MERGE_TAGGED_NAME, "{\"a\":{\"x\":1},\"b\":{\"foo\":{\"x\":1},\"y\":2}}\n", self::JSON, self::COMPACT, '.'];

        foreach (['legacy' => [], 'spec-fixed' => [self::FIXED_MERGE]] as $mode => $flags) {
            yield '!!merge on another name, lookup of its own name, ' . $mode => [self::MERGE_TAGGED_NAME, "{\"x\":1}\n", ...$flags, self::JSON, self::COMPACT, '.b.foo'];

            yield '!!merge on another name, to_entries, ' . $mode => [self::MERGE_TAGGED_NAME, "[{\"key\":\"foo\",\"value\":{\"x\":1}},{\"key\":\"y\",\"value\":2}]\n", ...$flags, self::JSON, self::COMPACT, '.b | to_entries'];

            yield '!!merge on another name, with_entries, ' . $mode => [self::MERGE_TAGGED_NAME, "{\"foo\":{\"x\":1},\"y\":2}\n", ...$flags, self::JSON, self::COMPACT, '.b | with_entries(.)'];
        }

        yield 'plain <<, lookup' => ['<<', "1\n", self::LOOKUP];

        yield 'plain <<, spec-fixed json' => ['<<', self::MERGED_JSON, self::FIXED_MERGE, self::JSON, self::COMPACT, '.'];
    }

    /**
     * Reading, deleting and iterating a mapping that holds both a `<<` key and a `!!merge` tag on another name
     * (both keys), whose two merge targets also share a key (shared key: legacy resolution lets the later merge
     * key win, spec-fixed the earlier), or a merge key next to the mapping's own copy of the merged key
     * (overridden). Every expected output is the reference's, except where a case says the
     * listing keeps this project's earlier output. Main, which treated only a plain `<<` as a merge key when
     * navigating, differs from the reference (and from these expectations) on `.m.y` and `.[]` over both keys,
     * `.[]` over the overridden mapping, and the legacy `.m.k` over the shared key; every other case is main's
     * output too.
     *
     * @param list<string> $flags
     */
    #[DataProvider('documentCases')]
    public function testMappingsWithSeveralMergeKeysReadAsTheReferenceDoes(string $document, array $flags, string $expression, string $expected): void
    {
        self::assertSame([0, $expected, ''], $this->invoke($document, ...$flags, ...[self::JSON, self::COMPACT, $expression]));
    }

    /**
     * @return iterable<string, array{string, list<string>, string, string}>
     */
    public static function documentCases(): iterable
    {
        foreach (['legacy' => [], 'spec-fixed' => [self::FIXED_MERGE]] as $mode => $flags) {
            yield 'both keys, the << key by its own text, ' . $mode => [self::BOTH_KEYS, $flags, '.m."<<"', "{\"x\":1}\n"];

            yield 'both keys, the !!merge key by its own name, ' . $mode => [self::BOTH_KEYS, $flags, '.m.foo', "{\"y\":3}\n"];

            yield 'both keys, a key merged through !!merge foo, ' . $mode => [self::BOTH_KEYS, $flags, '.m.y', "3\n"];

            yield 'both keys, deleting the !!merge key, ' . $mode => [self::BOTH_KEYS, $flags, 'del(.m.foo) | .m', "{\"x\":1,\"z\":2}\n"];

            yield 'both keys, deleting the << key, ' . $mode => [self::BOTH_KEYS, $flags, 'del(.m."<<") | .m', "{\"foo\":{\"y\":3},\"z\":2}\n"];

            // The reference lists the `<<` key itself here; this listing merges through it, as it always has.
            yield 'both keys, to_entries, ' . $mode => [self::BOTH_KEYS, $flags, self::TO_ENTRIES, "[{\"key\":\"x\",\"value\":1},{\"key\":\"foo\",\"value\":{\"y\":3}},{\"key\":\"z\",\"value\":2}]\n"];

            yield 'overridden, the !!merge key by its own name, ' . $mode => [self::OVERRIDDEN, $flags, '.m.foo', "{\"x\":5}\n"];

            yield 'overridden, the own key wins, ' . $mode => [self::OVERRIDDEN, $flags, '.m.x', "7\n"];

            yield 'overridden, .[] yields the merged value once, ' . $mode => [self::OVERRIDDEN, $flags, self::ITERATE, "[7]\n"];

            yield 'overridden, deleting the !!merge key, ' . $mode => [self::OVERRIDDEN, $flags, 'del(.m.foo) | .m', "{\"x\":7}\n"];

            yield 'overridden, to_entries, ' . $mode => [self::OVERRIDDEN, $flags, self::TO_ENTRIES, "[{\"key\":\"foo\",\"value\":{\"x\":5}},{\"key\":\"x\",\"value\":7}]\n"];

            yield 'overridden, with_entries, ' . $mode => [self::OVERRIDDEN, $flags, self::WITH_ENTRIES, "{\"foo\":{\"x\":5},\"x\":7}\n"];
        }

        yield 'both keys, .[] follows both, legacy' => [self::BOTH_KEYS, [], self::ITERATE, "[1,3,2]\n"];

        yield 'both keys, .[] takes the later merge key first, spec-fixed' => [self::BOTH_KEYS, [self::FIXED_MERGE], self::ITERATE, "[3,1,2]\n"];

        yield 'both keys, with_entries, legacy' => [self::BOTH_KEYS, [], self::WITH_ENTRIES, self::BOTH_KEYS_LISTED];

        // The reference gives {"x":1,"y":3,"z":2} here; this listing keeps the !!merge key under its own name, as it always has.
        yield 'both keys, with_entries, spec-fixed' => [self::BOTH_KEYS, [self::FIXED_MERGE], self::WITH_ENTRIES, self::BOTH_KEYS_LISTED];

        yield 'shared key, the later merge key wins, legacy' => [self::SHARED_KEY, [], '.m.k', "\"b\"\n"];

        yield 'shared key, the earlier merge key wins, spec-fixed' => [self::SHARED_KEY, [self::FIXED_MERGE], '.m.k', "\"a\"\n"];

        yield 'shared key, .[], spec-fixed' => [self::SHARED_KEY, [self::FIXED_MERGE], self::ITERATE, "[3,\"a\",1,2]\n"];
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
