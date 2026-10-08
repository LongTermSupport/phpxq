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
 * A tiny program must not be able to make jq allocate without bound, because running out of memory is a fatal
 * error no `try` can catch. Padding an array up to a far index and repeating a string fail with a jq error
 * past {@see AllocationLimit}, well before memory runs out.
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

        yield 'setpath far past the end'       => ['null | setpath([' . $past . ']; 1)', self::INDEX_TOO_LARGE];
        yield 'assignment far past the end'    => ['null | .[' . $past . '] = 1', self::INDEX_TOO_LARGE];
        yield 'update far past the end'        => ['[1] | .[' . ($past + 1) . '] |= 1', self::INDEX_TOO_LARGE];
        yield 'jq\'s own index limit'          => ['[] | .[536870911] = 1', self::INDEX_TOO_LARGE];
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
        $padding = AllocationLimit::MAX_PADDING;

        yield 'padding up to the limit'          => ['null | .[' . $padding . '] = 1 | length', (string)($padding + 1)];
        yield 'setpath up to the limit'          => ['null | setpath([' . $padding . ']; 1) | length', (string)($padding + 1)];
        yield 'a far index inside a long array'  => ['[range(' . ($padding + 10) . ')] | .[' . ($padding + 5) . '] = "x" | .[' . ($padding + 5) . ']', '"x"'];
        yield 'repeat below the limit'           => ['"ab" * 100000 | length', '200000'];
    }

    private function jq(string $program): string
    {
        $result = new CliRunner()->run(['jq', '-nc', $program]);
        self::assertSame('', $result->stderr);

        return $result->stdout;
    }
}
