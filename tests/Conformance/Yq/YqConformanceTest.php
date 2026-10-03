<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Conformance\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use LTS\PhpXq\Tests\Support\Conformance\YqConformanceSuite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Raw view of the documented examples of upstream yq (see fixtures/NOTICE.md) against the phpxq CLI,
 * deliberately ignoring known-gaps.txt. The case logic lives in {@see YqConformanceSuite}.
 *
 * @internal
 */
final class YqConformanceTest extends TestCase
{
    #[DataProvider('documentedExampleProvider')]
    public function testDocumentedExample(ConformanceCase $case): void
    {
        self::assertNull($case->evaluate(new CliRunner()), $case->id);
    }

    /**
     * @return iterable<string, array{ConformanceCase}>
     */
    public static function documentedExampleProvider(): iterable
    {
        foreach (new YqConformanceSuite()->cases() as $case) {
            yield $case->id => [$case];
        }
    }
}
