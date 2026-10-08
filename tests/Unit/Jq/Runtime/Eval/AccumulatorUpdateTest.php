<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Support\Jq\StandardProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The accumulator updates `reduce` and `foreach` apply in place (`. + x`, `. += x`, `.[k] = x`) give exactly
 * what the general operators give, whatever the accumulator and operand types.
 *
 * @internal
 */
final class AccumulatorUpdateTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('programs')]
    public function testProgram(string $program, string $input, array $expected): void
    {
        self::assertSame($expected, StandardProgram::outputs($program, $input));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function programs(): iterable
    {
        yield 'append arrays' => ['reduce range(4) as $i ([]; . + [$i, $i])', 'null', ['[0,0,1,1,2,2,3,3]']];
        yield 'append from null' => ['reduce range(3) as $i (null; . + [$i])', 'null', ['[0,1,2]']];
        yield 'append keeps the input intact' => ['. as $o | reduce range(2) as $i (.; . + [$i]) | [., $o]', '[9]', ['[[9,0,1],[9]]']];
        yield 'append the accumulator to itself' => ['reduce range(3) as $i ([]; . + [.])', 'null', ['[[],[[]],[[],[[]]]]']];
        yield 'plus-assign append' => ['reduce range(3) as $i ([]; . += [$i])', 'null', ['[0,1,2]']];
        yield 'plus-assign reads the accumulator' => ['reduce range(3) as $i ([1]; . += [length])', 'null', ['[1,1,2,3]']];
        yield 'add numbers' => ['reduce range(5) as $i (0; . + $i)', 'null', ['10']];
        yield 'add strings' => ['reduce ("a", "b", "c") as $s (""; . + $s)', 'null', ['"abc"']];
        yield 'add null operand' => ['reduce range(2) as $i ([1]; . + null)', 'null', ['[1]']];
        yield 'merge objects' => ['reduce range(3) as $i ({"1": "x"}; . + {($i | tostring): $i})', 'null', ['{"1":1,"0":0,"2":2}']];
        yield 'set object keys' => ['reduce ("b", "a", "b", "1") as $k ({}; .[$k] = $k + "!")', 'null', ['{"b":"b!","a":"a!","1":"1!"}']];
        yield 'set field from null' => ['reduce range(2) as $i (null; .count = $i)', 'null', ['{"count":1}']];
        yield 'set array indexes' => ['reduce range(3) as $i ([]; .[$i] = $i * 10)', 'null', ['[0,10,20]']];
        yield 'set array index past the end pads' => ['reduce (0, 3) as $i ([]; .[$i] = $i)', 'null', ['[0,null,null,3]']];
        yield 'set negative array index' => ['reduce (-1) as $i ([1, 2]; .[$i] = 9)', 'null', ['[1,9]']];
        yield 'set keeps the accumulator seen by the value' => ['reduce range(2) as $i ({}; .[$i | tostring] = .)', 'null', ['{"0":{},"1":{"0":{}}}']];
        yield 'set keeps the input intact' => ['. as $o | reduce range(2) as $i (.; .["k\($i)"] = $i) | [., $o]', '{"a":1}', ['[{"a":1,"k0":0,"k1":1},{"a":1}]']];
        yield 'foreach append' => ['[foreach range(3) as $i ([]; . + [$i])]', 'null', ['[[0],[0,1],[0,1,2]]']];
        yield 'foreach append with extract' => ['[foreach range(3) as $i ([]; . + [$i]; length)]', 'null', ['[1,2,3]']];
        yield 'foreach set' => ['[foreach ("a", "b") as $k ({}; .[$k] = 1)]', 'null', ['[{"a":1},{"a":1,"b":1}]']];
        yield 'update with several outputs keeps the last' => ['reduce range(2) as $i ([]; . + ([$i], [$i * 10]))', 'null', ['[0,10]']];
        yield 'update with no output leaves null' => ['reduce range(2) as $i ([1]; . + empty)', 'null', ['null']];
    }

    #[DataProvider('failingPrograms')]
    public function testFailingProgramKeepsItsError(string $program, string $message): void
    {
        try {
            StandardProgram::outputs($program);
        } catch (JqException $jqException) {
            self::assertSame($message, $jqException->getMessage());

            return;
        }

        self::fail('expected a jq error');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingPrograms(): iterable
    {
        yield 'append a number to an array' => ['reduce range(1) as $i ([]; . + $i)', 'array ([]) and number (0) cannot be added'];
        yield 'number key on an object' => ['reduce range(1) as $i ({}; .[$i] = 1)', 'Cannot index object with number (0)'];
        yield 'string key on an array' => ['reduce range(1) as $i ([]; .["a"] = 1)', 'Cannot index array with string ("a")'];
        yield 'negative index out of range' => ['reduce range(1) as $i ([]; .[-1] = 1)', 'Out of bounds negative array index'];
    }
}
