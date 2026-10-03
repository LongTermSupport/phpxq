<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\EnvFileCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EnvFileCalls::class)]
final class EnvFileCallsTest extends TestCase
{
    #[DataProvider('failures')]
    public function testLoadFailures(string $expression, string $message): void
    {
        try {
            YqHarness::run($expression, '', true);
        } catch (EvaluationException $evaluationException) {
            self::assertSame($message, $evaluationException->getMessage());

            return;
        }

        self::fail('expected an EvaluationException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'load missing file' => ['load("/nonexistent/cat.yml")', 'failed to load /nonexistent/cat.yml: open /nonexistent/cat.yml: no such file or directory'];
        yield 'strload missing file' => ['strload("/nonexistent/cat.yml")', 'failed to load /nonexistent/cat.yml: open /nonexistent/cat.yml: no such file or directory'];
        yield 'load null filename' => ['load(.a)', 'filename expression returned nil'];
        yield 'strload null filename' => ['strload(.a)', 'filename expression returned nil'];
    }
}
