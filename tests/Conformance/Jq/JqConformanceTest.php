<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Conformance\Jq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use LTS\PhpXq\Tests\Support\Conformance\JqConformanceSuite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Raw view of the vendored upstream jq test suite (see fixtures/NOTICE.md): every case through the CLI,
 * deliberately ignoring known-gaps.txt. The case logic lives in {@see JqConformanceSuite}.
 *
 * @internal
 */
final class JqConformanceTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function testUpstreamCase(ConformanceCase $case): void
    {
        self::assertNull($case->evaluate(new CliRunner()), $case->id);
    }

    /**
     * @return iterable<string, array{ConformanceCase}>
     */
    public static function provideCases(): iterable
    {
        foreach (new JqConformanceSuite()->cases() as $case) {
            yield $case->id => [$case];
        }
    }
}
