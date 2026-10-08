<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\Assignment;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Limits\AllocationLimit;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Padding an array up to a far index and repeating a string fail with an ordinary jq error past the fixed bounds
 * of {@see AllocationLimit}. Those bounds do not prevent running out of memory, which stays a fatal error no
 * `try` can catch.
 *
 * @internal
 */
#[CoversClass(PathOps::class)]
#[CoversClass(Assignment::class)]
#[CoversClass(Arithmetic::class)]
#[Medium]
final class AllocationLimitTest extends TestCase
{
    private const string INDEX_TOO_LARGE = '"Array index too large"';

    private const string REPEAT_TOO_LONG = '"Repeat string result too long"';

    #[DataProvider('refused')]
    public function testHugeAllocationsAreCatchableErrors(string $program, string $error): void
    {
        self::assertSame($error . "\n", $this->jq('try (' . $program . ') catch .'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        $past = AllocationLimit::MAX_PADDING + 1;

        yield 'setpath far past the end'       => ['null | setpath([' . $past . ']; 1)', self::paddingError($past)];
        yield 'assignment far past the end'    => ['null | .[' . $past . '] = 1', self::paddingError($past)];
        yield 'update far past the end'        => ['[1] | .[' . ($past + 1) . '] |= 1', self::paddingError($past + 1)];
        yield 'past jq\'s own index limit'     => ['[] | .[' . (AllocationLimit::MAX_ARRAY_INDEX + 1) . '] = 1', self::INDEX_TOO_LARGE];
        yield 'jq\'s index limit is also far'  => ['[] | .[' . AllocationLimit::MAX_ARRAY_INDEX . '] = 1', self::paddingError(AllocationLimit::MAX_ARRAY_INDEX)];
        yield 'repeat past the string limit'   => ['"x" * ' . (AllocationLimit::MAX_STRING_BYTES + 1), self::REPEAT_TOO_LONG];
        yield 'repeat of a longer string'      => ['"ab" * ' . (intdiv(AllocationLimit::MAX_STRING_BYTES, 2) + 1), self::REPEAT_TOO_LONG];
        yield 'repeat a huge number of times'  => ['"x" * 2147483647', self::REPEAT_TOO_LONG];
    }

    #[DataProvider('allowed')]
    public function testAllocationsWithinTheLimitsStillWork(string $program, string $output): void
    {
        self::assertSame($output . "\n", $this->jq($program));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowed(): iterable
    {
        // padding and repetition that jq 1.6 performs, a long way below the bounds
        yield 'padding two million places'       => ['null | .[2000000] = 1 | length', '2000001'];
        yield 'setpath a million and a half'     => ['[] | setpath([1500000]; 1) | length', '1500001'];
        yield 'a far index inside a long array'  => ['[range(2000010)] | .[2000005] = "x" | .[2000005]', '"x"'];
        yield 'repeat below the limit'           => ['"ab" * 100000 | length', '200000'];
        yield 'repeat as far as jq 1.6 does'     => ['"x" * 300000000 | length', '300000000'];
    }

    private static function paddingError(int $index): string
    {
        return '"' . \sprintf(PathOps::PADDING_TOO_FAR, $index, AllocationLimit::MAX_PADDING) . '"';
    }

    private function jq(string $program): string
    {
        $result = new CliRunner()->run(['jq', '-nc', $program]);
        self::assertSame('', $result->stderr);

        return $result->stdout;
    }
}
