<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml;

use LTS\PhpXq\Yaml\MergeKey;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Which keys are merge keys, as the reference decides it (yq 4.54): navigation goes by the `!!merge` tag alone;
 * the encoders and `explode` take any scalar `<<` in the legacy mode and only a `<<` tagged `!!merge` with
 * `--yaml-fix-merge-anchor-to-spec`.
 *
 * @internal
 */
#[CoversClass(MergeKey::class)]
#[Small]
final class MergeKeyTest extends TestCase
{
    private const string NAME = '<<';

    private const string MERGE = '!!merge';

    private const string STR = '!!str';

    #[DataProvider('keys')]
    public function testEachUseDecidesAsTheReferenceDoes(Node $key, bool $navigates, bool $legacyOutput, bool $fixedOutput): void
    {
        self::assertSame(
            [$navigates, $legacyOutput, $fixedOutput],
            [MergeKey::navigates($key), MergeKey::merges($key, false), MergeKey::merges($key, true)],
        );
    }

    /**
     * @return iterable<string, array{Node, bool, bool, bool}>
     */
    public static function keys(): iterable
    {
        yield 'plain <<' => [Node::scalar(self::NAME, self::MERGE), true, true, true];

        yield 'quoted <<' => [Node::scalar(self::NAME, self::STR, NodeStyleEnum::DoubleQuoted), false, true, false];

        yield '!!str <<' => [Node::scalar(self::NAME, self::STR), false, true, false];

        yield 'custom-tagged <<' => [Node::scalar(self::NAME, '!x'), false, true, false];

        yield '!!binary <<' => [Node::scalar(self::NAME, '!!binary'), false, true, false];

        yield '!!merge on another name' => [Node::scalar('foo', self::MERGE), true, false, false];

        yield 'ordinary key' => [Node::scalar('a', self::STR), false, false, false];

        yield 'collection key' => [Node::sequence(), false, false, false];
    }
}
