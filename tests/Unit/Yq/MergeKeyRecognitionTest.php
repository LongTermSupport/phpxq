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
     * @return array{int, string, string}
     */
    private function invoke(string $stdin, string ...$arguments): array
    {
        $result = new CliRunner()->run(['yq', ...array_values($arguments)], $stdin);

        return [$result->exitCode, $result->stdout, $result->stderr];
    }
}
