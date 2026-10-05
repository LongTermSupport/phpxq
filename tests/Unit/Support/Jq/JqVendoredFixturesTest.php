<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Jq;

use LTS\PhpXq\Tests\Support\Jq\JqTestFileParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class JqVendoredFixturesTest extends TestCase
{
    #[DataProvider('provideVendoredFiles')]
    public function testParsedCaseCountsMatchUpstream(string $file, int $expectedTotal, int $expectedFailCases): void
    {
        $cases = new JqTestFileParser()->parseFile(__DIR__ . '/../../../Conformance/Jq/fixtures/' . $file);

        self::assertCount($expectedTotal, $cases);
        self::assertCount(
            $expectedFailCases,
            array_filter($cases, static fn (\LTS\PhpXq\Tests\Support\Jq\JqTestCase $case): bool => $case->shouldFail),
        );
    }

    /**
     * @return iterable<string, array{string, int, int}> file, total cases, %%FAIL cases
     */
    public static function provideVendoredFiles(): iterable
    {
        yield 'jq.test' => ['jq.test', 550, 19];

        yield 'man.test' => ['man.test', 231, 0];

        yield 'onig.test' => ['onig.test', 47, 0];

        yield 'uri.test' => ['uri.test', 20, 0];

        yield 'manonig.test' => ['manonig.test', 19, 0];

        yield 'base64.test' => ['base64.test', 10, 0];

        yield 'optional.test' => ['optional.test', 2, 0];
    }

    public function testTotalCaseCountAcrossAllFilesIs879(): void
    {
        $parser = new JqTestFileParser();
        $total  = 0;
        foreach (['jq', 'man', 'onig', 'uri', 'manonig', 'base64', 'optional'] as $name) {
            $total += \count($parser->parseFile(__DIR__ . '/../../../Conformance/Jq/fixtures/' . $name . '.test'));
        }

        self::assertSame(879, $total);
    }
}
