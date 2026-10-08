<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Limits\AllocationLimit;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Format\Codec\PropsDecoder;
use LTS\PhpXq\Yq\Runtime\Detached;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Padding a sequence up to a far index fails past {@see AllocationLimit::MAX_PADDING}, or when it would not fit in
 * what the memory_limit leaves, and repeating a string follows Go yq: an integer count only, never negative, and
 * at most 10 MiB of result. Running out of memory is still possible by other routes; these checks narrow them.
 *
 * @internal
 */
#[CoversClass(Detached::class)]
#[CoversClass(PropsDecoder::class)]
#[CoversClass(ArithmeticOperator::class)]
#[Medium]
final class AllocationLimitTest extends TestCase
{
    private const array FROM_PROPERTIES = ['-p', 'props', '.'];

    private const string MEMORY_LIMIT = 'memory_limit';

    private const int HEADROOM = 268435456;

    private const int TOO_MANY_FOR_THE_HEADROOM = 1000000;

    private const int FITS_IN_THE_HEADROOM = 100000;

    /**
     * @param list<string> $args
     */
    #[DataProvider('refused')]
    public function testHugeAllocationsAreErrors(array $args, string $stdin, string $error): void
    {
        $result = new CliRunner()->run(['yq', ...$args], $stdin);

        self::assertSame(1, $result->exitCode);
        self::assertSame('Error: ' . $error . "\n", $result->stderr);
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function refused(): iterable
    {
        $past = AllocationLimit::MAX_PADDING + 1;

        yield 'index assignment far past the end' => [['-n', self::assignAt('.', $past)], '', \sprintf('cannot pad a sequence to index %d: more than %d new entries', $past, AllocationLimit::MAX_PADDING)];
        yield 'nested index far past the end'     => [['-n', self::assignAt('.a', $past)], '', \sprintf('cannot pad a sequence to index %d: more than %d new entries', $past, AllocationLimit::MAX_PADDING)];
        yield 'properties index far past the end' => [self::FROM_PROPERTIES, self::propertyAt($past), \sprintf('bad file \'-\': properties: cannot pad a sequence to index %d: more than %d new entries', $past, AllocationLimit::MAX_PADDING)];
        yield 'repeat past 10 MiB'                => [['-n', '"ab" * 100000000'], '', 'result of repeating string (2 bytes) by 100000000 would exceed 10485760 bytes'];
        yield 'repeat a huge float'               => [['-n', '"ab" * 1e12'], '', 'cannot multiply !!str with !!float'];
        yield 'repeat a fraction'                 => [['-n', '"ab" * 2.5'], '', 'cannot multiply !!str with !!float'];
        yield 'repeat a negative count'           => [['-n', '"ab" * -1'], '', 'cannot repeat string by a negative number (-1)'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('allowed')]
    public function testAllocationsWithinTheLimitsStillWork(array $args, string $stdin, string $output): void
    {
        $result = new CliRunner()->run(['yq', ...$args], $stdin);

        self::assertSame('', $result->stderr);
        self::assertSame($output . "\n", $result->stdout);
    }

    /**
     * Under a memory_limit that leaves 256 MiB, a million new entries are refused before padding starts, from an
     * expression and from properties input alike, while a hundred thousand still fit and run.
     */
    public function testPaddingThatWouldNotFitInTheMemoryLimitIsAnError(): void
    {
        $tooMany = self::TOO_MANY_FOR_THE_HEADROOM;
        $saved   = ini_get(self::MEMORY_LIMIT);
        ini_set(self::MEMORY_LIMIT, (string)(memory_get_usage() + self::HEADROOM));
        try {
            $assigned   = new CliRunner()->run(['yq', '-n', self::assignAt('.a', $tooMany)]);
            $properties = new CliRunner()->run(['yq', ...self::FROM_PROPERTIES], self::propertyAt($tooMany));
            $fits       = new CliRunner()->run(['yq', '-n', self::assignAt('.a', self::FITS_IN_THE_HEADROOM) . ' | .a | length']);
        } finally {
            ini_set(self::MEMORY_LIMIT, $saved);
        }

        $error = \sprintf(AllocationLimit::PADDING_MEMORY_ERROR, $tooMany, $tooMany);
        self::assertSame('Error: ' . $error . "\n", $assigned->stderr);
        self::assertSame("Error: bad file '-': properties: " . $error . "\n", $properties->stderr);
        self::assertSame(['', (self::FITS_IN_THE_HEADROOM + 1) . "\n"], [$fits->stderr, $fits->stdout]);
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function allowed(): iterable
    {
        // padding that Go yq performs: a long way below the limit, which only stops what cannot fit in memory
        yield 'nested index two million places'  => [['-n', '.b[2000000] = 1 | .b | length'], '', '2000001'];
        yield 'properties index'                 => [['-p', 'props', '-o', 'json', '-I0', '.'], "a.2 = x\n", '{"a":[null,null,"x"]}'];
        yield 'repeat'                           => [['-n', '"ab" * 3'], '', 'ababab'];
        yield 'repeat up to 10 MiB'              => [['-n', '"ab" * 5242880 | length'], '', '10485760'];
        yield 'repeat zero times'                => [['-n', '"ab" * 0'], '', ''];
    }

    /**
     * An expression assigning 1 at the index of the sequence the path names.
     */
    private static function assignAt(string $path, int $index): string
    {
        return $path . '[' . $index . '] = 1';
    }

    /**
     * Properties input setting the index of the sequence `a`.
     */
    private static function propertyAt(int $index): string
    {
        return 'a.' . $index . " = x\n";
    }
}
