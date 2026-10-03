<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ErrorText;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ErrorText::class)]
final class ErrorTextTest extends TestCase
{
    public function testJsonIsCompact(): void
    {
        self::assertSame('{"a":[1,2]}', ErrorText::json(new JsonObject(['a' => [1, 2]])));
    }

    #[DataProvider('dumps')]
    public function testDumpTruncates(mixed $value, string $expected): void
    {
        self::assertSame($expected, ErrorText::dump($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function dumps(): iterable
    {
        yield 'short value'        => [[1, 2, 3], '[1,2,3]'];
        yield 'short string'       => ['abc', '"abc"'];
        yield 'long string'        => ['very-long-long-long-long-string', '"very-long-long-long-long..."'];
        yield 'unicode string'     => ['xxxx' . str_repeat('☆', 8), '"xxxx☆☆☆☆☆☆..."'];
        yield 'fits exactly'       => ['xx' . str_repeat('☆', 8), '"xx☆☆☆☆☆☆☆☆"'];
        yield 'long array'         => [range(1, 20), '[1,2,3,4,5,6,7,8,9,10,11,1...'];
        yield 'precise number'     => [new PreciseNumber(1.2345678901234568e29, '123456789012345678901234567890'), '12345678901234567890123456...'];
    }

    public function testTypeError(): void
    {
        self::assertSame('string ("x") cannot be negated', ErrorText::typeError('x', 'cannot be negated')->getMessage());
    }

    public function testTypeError2(): void
    {
        self::assertSame(
            'number (1) and string ("a") cannot be added',
            ErrorText::typeError2(1, 'a', 'cannot be added')->getMessage(),
        );
    }

    public function testIndexAndIterateErrors(): void
    {
        self::assertSame('Cannot index number with string ("a")', ErrorText::indexError(1, 'a')->getMessage());
        self::assertSame('Cannot iterate over null (null)', ErrorText::iterateError(null)->getMessage());
    }
}
