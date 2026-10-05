<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The operator arithmetic on bare nodes: tags of numeric results, ownership of shared children and the
 * compound assignment spellings. A row is operator, left value and tag, right value and tag, expected value and
 * tag, separated by pipes; a tilde stands for the schema's own tag.
 *
 * @internal
 */
#[CoversClass(ArithmeticOperator::class)]
final class ArithmeticOperatorApplyTest extends TestCase
{
    private const string CUSTOM = '!foo';

    private const string REPEATED = 'abab';

    private const string DAY = '24h';

    private const string NEW = 'new';

    private const array OPERATORS = [
        'Add'            => BinaryOperatorEnum::Add,
        'AddAssign'      => BinaryOperatorEnum::AddAssign,
        'Subtract'       => BinaryOperatorEnum::Subtract,
        'SubtractAssign' => BinaryOperatorEnum::SubtractAssign,
        'Multiply'       => BinaryOperatorEnum::Multiply,
        'MultiplyAssign' => BinaryOperatorEnum::MultiplyAssign,
        'Divide'         => BinaryOperatorEnum::Divide,
        'DivideAssign'   => BinaryOperatorEnum::DivideAssign,
        'Modulo'         => BinaryOperatorEnum::Modulo,
        'ModuloAssign'   => BinaryOperatorEnum::ModuloAssign,
    ];

    /**
     * @param callable(): mixed $call
     */
    private static function assertRejected(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('the arithmetic does not apply');
        } catch (EvaluationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return Generator<string, list<string>>
     */
    public static function numericProvider(): Generator
    {
        $table = <<<'TABLE'
            Add|3|!foo|4|~|7|!foo
            Add|3|~|4|!foo|7|!!int
            Add|3|!!float|4|~|7|!!int
            Add|9007199254740993|~|0|~|9007199254740993|!!int
            Subtract|9007199254740993|~|0|~|9007199254740993|!!int
            Multiply|9007199254740993|~|1|~|9007199254740993|!!int
            Add|1.5|!foo|1|~|2.5|!foo
            Add|1.5|~|1|~|2.5|!!float
            Add|3|!x|4|~|7|!x
            Add|3|!|4|~|7|!
            AddAssign|3|!foo|4|~|7|!foo
            AddAssign|3|~|4|~|7|!!int
            Subtract|7|!foo|4|~|3|!foo
            Subtract|7|~|4|~|3|!!int
            Subtract|7|!!float|4|~|3|!!int
            Subtract|7.5|~|4|~|3.5|!!float
            SubtractAssign|7|!foo|4|~|3|!foo
            SubtractAssign|7|~|4|~|3|!!int
            Multiply|3|!foo|4|~|12|!foo
            Multiply|3|~|4|~|12|!!int
            Multiply|3|!!float|4|~|12|!!int
            Multiply|2.5|~|2|~|5|!!int
            MultiplyAssign|3|!foo|4|~|12|!foo
            MultiplyAssign|3|~|4|~|12|!!int
            Divide|6|!foo|4|~|1.5|!foo
            Divide|6|~|4|~|1.5|!!float
            Divide|6|!!int|4|~|1.5|!!float
            Divide|6|~|3|~|2|!!int
            DivideAssign|6|!foo|4|~|1.5|!foo
            DivideAssign|6|~|4|~|1.5|!!float
            Modulo|7|!foo|4|~|3|!foo
            Modulo|7|~|4|~|3|!!int
            Modulo|7|!!float|4|~|3|!!int
            Modulo|7.5|~|2|~|1.5|!!float
            Modulo|7|~|2.5|~|2|!!int
            Modulo|6|~|3|~|0|!!int
            Modulo|6|~|3.0|~|0|!!int
            Modulo|-6|~|-1|~|0|!!int
            Modulo|5|~|-1|~|0|!!int
            Modulo|5|~|1|~|0|!!int
            ModuloAssign|7|!foo|4|~|3|!foo
            ModuloAssign|7|~|4|~|3|!!int
            TABLE;

        foreach (explode("\n", $table) as $line) {
            yield trim($line) => array_map(static fn (string $field): string => '~' === $field ? '' : $field, explode('|', trim($line)));
        }
    }

    #[DataProvider('numericProvider')]
    public function testNumericResultsKeepCustomTags(string $operator, string $left, string $leftTag, string $right, string $rightTag, string $value, string $tag): void
    {
        $result = ArithmeticOperator::apply(
            self::OPERATORS[$operator],
            Node::scalar($left, $leftTag),
            Node::scalar($right, $rightTag),
        );

        self::assertInstanceOf(Node::class, $result);
        self::assertSame($value, $result->value);
        self::assertSame($tag, $result->tag);
    }

    public function testUnsupportedOperatorsAreNamed(): void
    {
        self::assertRejected('Unsupported arithmetic operator ,', static fn (): ?Node => ArithmeticOperator::apply(BinaryOperatorEnum::Union, Node::scalar('1'), Node::scalar('2')));
    }

    /**
     * @return Generator<string, array{BinaryOperatorEnum, bool, bool}>
     */
    public static function missingSideProvider(): Generator
    {
        yield 'add nothing' => [BinaryOperatorEnum::Add, false, false];
        yield 'add left missing' => [BinaryOperatorEnum::Add, false, true];
        yield 'add right missing' => [BinaryOperatorEnum::Add, true, false];
        yield 'subtract left missing' => [BinaryOperatorEnum::Subtract, false, true];
        yield 'subtract right missing' => [BinaryOperatorEnum::Subtract, true, false];
        yield 'multiply left missing' => [BinaryOperatorEnum::Multiply, false, true];
        yield 'multiply right missing' => [BinaryOperatorEnum::Multiply, true, false];
        yield 'divide left missing' => [BinaryOperatorEnum::Divide, false, true];
        yield 'divide right missing' => [BinaryOperatorEnum::Divide, true, false];
        yield 'modulo left missing' => [BinaryOperatorEnum::Modulo, false, true];
        yield 'modulo right missing' => [BinaryOperatorEnum::Modulo, true, false];
    }

    #[DataProvider('missingSideProvider')]
    public function testAMissingSideYieldsTheOtherOrNothing(BinaryOperatorEnum $operator, bool $hasLeft, bool $hasRight): void
    {
        $left   = $hasLeft ? Node::scalar('5') : null;
        $right  = $hasRight ? Node::scalar('7') : null;
        $result = ArithmeticOperator::apply($operator, $left, $right);

        if (!$hasLeft && !$hasRight) {
            self::assertNotInstanceOf(Node::class, $result);

            return;
        }

        if (BinaryOperatorEnum::Subtract === $operator && !$hasLeft) {
            self::assertNotInstanceOf(Node::class, $result);

            return;
        }

        self::assertInstanceOf(Node::class, $result);
        self::assertSame($hasLeft ? '5' : '7', $result->value);
        self::assertNotSame($hasLeft ? $left : $right, $result, 'the survivor is copied');
    }

    public function testRepeatedStringsTakeTheLeftTagAndDefaultStyle(): void
    {
        $text   = Node::scalar('ab', self::CUSTOM, NodeStyleEnum::DoubleQuoted);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, $text, Node::scalar('2'));

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(self::REPEATED, $result->value);
        self::assertSame(NodeStyleEnum::Default, $result->style);

        $none = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('ab', '', NodeStyleEnum::DoubleQuoted), Node::scalar('0'));
        self::assertInstanceOf(Node::class, $none);
        self::assertSame('', $none->value);

        $negative = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('ab', '', NodeStyleEnum::DoubleQuoted), Node::scalar('-3'));
        self::assertInstanceOf(Node::class, $negative);
        self::assertSame('', $negative->value);

        $once = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('ab', '', NodeStyleEnum::DoubleQuoted), Node::scalar('1'));
        self::assertInstanceOf(Node::class, $once);
        self::assertSame('ab', $once->value);
    }

    public function testConcatenationConvertsTheTagOfNonStrings(): void
    {
        $number = ArithmeticOperator::apply(BinaryOperatorEnum::Add, Node::scalar('1', '', NodeStyleEnum::Default), Node::scalar('x', '', NodeStyleEnum::DoubleQuoted));
        self::assertInstanceOf(Node::class, $number);
        self::assertSame('1x', $number->value);
        self::assertSame(CoreSchema::TAG_STR, $number->tag);
        self::assertSame(NodeStyleEnum::Default, $number->style);

        $text = ArithmeticOperator::apply(BinaryOperatorEnum::Add, Node::scalar('a', '', NodeStyleEnum::DoubleQuoted), Node::scalar('b', '', NodeStyleEnum::DoubleQuoted));
        self::assertInstanceOf(Node::class, $text);
        self::assertSame('ab', $text->value);
        self::assertSame(NodeStyleEnum::DoubleQuoted, $text->style);
    }

    public function testDatesIgnoreNonStringDurations(): void
    {
        $date = Node::scalar('2001-12-15T02:59:43Z', CoreSchema::TAG_STR);

        $zero = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $date, Node::scalar('0'));
        self::assertInstanceOf(Node::class, $zero);
        self::assertSame('2001-12-15T02:59:43Z0', $zero->value);

        $word = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $date, Node::scalar('bad', CoreSchema::TAG_STR));
        self::assertInstanceOf(Node::class, $word);
        self::assertSame('2001-12-15T02:59:43Zbad', $word->value);
    }

    public function testDatesUseTheGivenLayout(): void
    {
        $date  = Node::scalar('15/12/2001', CoreSchema::TAG_STR);
        $day   = Node::scalar(self::DAY, CoreSchema::TAG_STR);
        $moved = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $date, $day, '', '02/01/2006');

        self::assertInstanceOf(Node::class, $moved);
        self::assertSame('16/12/2001', $moved->value);

        $back = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $date, $day, '', '02/01/2006');
        self::assertInstanceOf(Node::class, $back);
        self::assertSame('14/12/2001', $back->value);

        $default = ArithmeticOperator::apply(BinaryOperatorEnum::Add, Node::scalar('2001-12-15', CoreSchema::TAG_STR), $day);
        self::assertInstanceOf(Node::class, $default);
        self::assertSame('2001-12-16T00:00:00Z', $default->value);
    }

    /**
     * @return array{Node, Node}
     */
    private static function sequences(): array
    {
        return [
            Node::sequence([Node::scalar('1'), Node::scalar('2')]),
            Node::sequence([Node::scalar('3'), Node::scalar('4')]),
        ];
    }

    public function testSequenceAdditionCopiesBothSidesByDefault(): void
    {
        [$left, $right] = self::sequences();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right);

        self::assertInstanceOf(Node::class, $result);
        self::assertCount(4, $result->content);
        foreach ([...$left->content, ...$right->content] as $index => $original) {
            self::assertNotSame($original, $result->content[$index]);
        }
    }

    public function testSequenceAdditionHandsOverTheReplacedLeftSide(): void
    {
        [$left, $right] = self::sequences();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::AddAssign, $left, $right, '', null, $left);

        self::assertInstanceOf(Node::class, $result);
        self::assertSame($left->content[0], $result->content[0]);
        self::assertSame($left->content[1], $result->content[1]);
        self::assertNotSame($right->content[0], $result->content[2]);
        self::assertNotSame($right->content[1], $result->content[3]);
        self::assertNotSame($left, $result);
    }

    public function testSequenceAdditionHandsOverTheReplacedRightSide(): void
    {
        [$left, $right] = self::sequences();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right, '', null, $right);

        self::assertInstanceOf(Node::class, $result);
        self::assertNotSame($left->content[0], $result->content[0]);
        self::assertNotSame($left->content[1], $result->content[1]);
        self::assertSame($right->content[0], $result->content[2]);
        self::assertSame($right->content[1], $result->content[3]);
    }

    public function testNothingIsHandedOverWhenBothSidesAreTheReplacedNode(): void
    {
        [$both] = self::sequences();
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $both, $both, '', null, $both);

        self::assertInstanceOf(Node::class, $result);
        self::assertCount(4, $result->content);
        foreach ([...$both->content, ...$both->content] as $index => $original) {
            self::assertNotSame($original, $result->content[$index]);
        }
    }

    public function testNothingIsHandedOverForAnUnrelatedReplacedNode(): void
    {
        [$left, $right] = self::sequences();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right, '', null, Node::scalar('9'));

        self::assertInstanceOf(Node::class, $result);
        self::assertNotSame($left->content[0], $result->content[0]);
        self::assertNotSame($right->content[0], $result->content[2]);
    }

    public function testASingleItemIsAppendedAsAShallowCopy(): void
    {
        [$left] = self::sequences();
        $item   = Node::mapping([Node::scalar('k'), Node::scalar('v')]);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $item);

        self::assertInstanceOf(Node::class, $result);
        self::assertCount(3, $result->content);
        self::assertNotSame($item, $result->content[2]);
        self::assertSame($item->content[0], $result->content[2]->content[0]);
    }

    public function testASequenceKeepsItsStyleAndTag(): void
    {
        $left       = Node::sequence([Node::scalar('1')], NodeStyleEnum::Flow);
        $left->tag  = self::CUSTOM;
        $right      = Node::sequence([Node::scalar('2')]);
        $result     = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right);
        $subtracted = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $left, Node::scalar('1'));

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(NodeStyleEnum::Flow, $result->style);
        self::assertSame(self::CUSTOM, $result->tag);
        self::assertInstanceOf(Node::class, $subtracted);
        self::assertSame(NodeStyleEnum::Flow, $subtracted->style);
        self::assertSame(self::CUSTOM, $subtracted->tag);
        self::assertSame([], $subtracted->content);
    }

    /**
     * @return Generator<string, array{NodeStyleEnum, NodeStyleEnum, NodeStyleEnum}>
     */
    public static function appendedStyleProvider(): Generator
    {
        yield 'quoted neighbour' => [NodeStyleEnum::DoubleQuoted, NodeStyleEnum::Default, NodeStyleEnum::DoubleQuoted];
        yield 'single quoted neighbour' => [NodeStyleEnum::SingleQuoted, NodeStyleEnum::Default, NodeStyleEnum::SingleQuoted];
        yield 'plain neighbour' => [NodeStyleEnum::Default, NodeStyleEnum::Default, NodeStyleEnum::Default];
        yield 'own style wins' => [NodeStyleEnum::DoubleQuoted, NodeStyleEnum::SingleQuoted, NodeStyleEnum::SingleQuoted];
        yield 'own style over plain neighbour' => [NodeStyleEnum::Default, NodeStyleEnum::SingleQuoted, NodeStyleEnum::SingleQuoted];
    }

    #[DataProvider('appendedStyleProvider')]
    public function testAppendedScalarsBorrowTheStyleOfTheLastItem(NodeStyleEnum $last, NodeStyleEnum $added, NodeStyleEnum $expected): void
    {
        $left   = Node::sequence([Node::scalar('first', '', NodeStyleEnum::Literal), Node::scalar('last', CoreSchema::TAG_STR, $last)]);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, Node::scalar(self::NEW, CoreSchema::TAG_STR, $added));

        self::assertInstanceOf(Node::class, $result);
        self::assertSame($expected, $result->content[2]->style);
    }

    public function testOnlyTheLastItemDecidesTheStyle(): void
    {
        $left   = Node::sequence([Node::scalar('first', CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted), Node::scalar('last', CoreSchema::TAG_STR, NodeStyleEnum::Default)]);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, Node::scalar(self::NEW, CoreSchema::TAG_STR));

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(NodeStyleEnum::Default, $result->content[2]->style);
    }

    public function testNonScalarNeighboursDoNotLendTheirStyle(): void
    {
        $left   = Node::sequence([Node::mapping([], NodeStyleEnum::DoubleQuoted)]);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, Node::scalar(self::NEW, CoreSchema::TAG_STR));

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(NodeStyleEnum::Default, $result->content[1]->style);

        $quoted = Node::sequence([Node::scalar('x', CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted)]);
        $nested = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $quoted, Node::sequence([Node::scalar('y')]));
        self::assertInstanceOf(Node::class, $nested);
        self::assertSame(NodeStyleEnum::Default, $nested->content[1]->style);

        $map = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $quoted, Node::mapping([], NodeStyleEnum::Default));
        self::assertInstanceOf(Node::class, $map);
        self::assertSame(NodeStyleEnum::Default, $map->content[1]->style);
    }

    public function testAppendingToAnEmptySequence(): void
    {
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, Node::sequence(), Node::scalar('only', CoreSchema::TAG_STR, NodeStyleEnum::Default));

        self::assertInstanceOf(Node::class, $result);
        self::assertCount(1, $result->content);
        self::assertSame('only', $result->content[0]->value);
    }

    public function testSequenceSubtractionCopiesOrHandsOverTheKeptItems(): void
    {
        [$left] = self::sequences();
        $drop   = Node::sequence([Node::scalar('2')]);

        $copied = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $left, $drop);
        self::assertInstanceOf(Node::class, $copied);
        self::assertCount(1, $copied->content);
        self::assertNotSame($left->content[0], $copied->content[0]);

        $shared = ArithmeticOperator::apply(BinaryOperatorEnum::SubtractAssign, $left, $drop, '', null, $left);
        self::assertInstanceOf(Node::class, $shared);
        self::assertSame($left->content[0], $shared->content[0]);

        $single = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $left, Node::scalar('1'), '', null, $left);
        self::assertInstanceOf(Node::class, $single);
        self::assertCount(1, $single->content);
        self::assertSame($left->content[1], $single->content[0]);
    }

    public function testSubtractingNullOrNothingCopiesTheLeftSide(): void
    {
        $left = Node::scalar('5');

        $nothing = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $left, null);
        self::assertInstanceOf(Node::class, $nothing);
        self::assertNotSame($left, $nothing);
        self::assertSame('5', $nothing->value);

        $null = ArithmeticOperator::apply(BinaryOperatorEnum::Subtract, $left, Node::scalar('null'));
        self::assertInstanceOf(Node::class, $null);
        self::assertNotSame($left, $null);
        self::assertSame('5', $null->value);
    }

    /**
     * @return array{Node, Node}
     */
    private static function mappings(): array
    {
        return [
            Node::mapping([Node::scalar('a'), Node::scalar('1'), Node::scalar('b'), Node::scalar('2')]),
            Node::mapping([Node::scalar('b'), Node::scalar('3'), Node::scalar('c'), Node::scalar('4')]),
        ];
    }

    public function testMappingAdditionMergesKeysAndCopiesByDefault(): void
    {
        [$left, $right] = self::mappings();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right);

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(['a', '1', 'b', '3', 'c', '4'], array_map(static fn (Node $node): string => $node->value, $result->content));
        self::assertNotSame($left->content[0], $result->content[0]);
        self::assertNotSame($right->content[1], $result->content[3]);
        self::assertNotSame($right->content[2], $result->content[4]);
        self::assertSame('2', $left->content[3]->value, 'the left mapping is untouched');
    }

    public function testMappingAdditionHandsOverTheReplacedLeftSide(): void
    {
        [$left, $right] = self::mappings();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::AddAssign, $left, $right, '', null, $left);

        self::assertInstanceOf(Node::class, $result);
        self::assertNotSame($left, $result);
        self::assertSame($left->content[0], $result->content[0]);
        self::assertSame($left->content[1], $result->content[1]);
        self::assertNotSame($right->content[2], $result->content[4]);
        self::assertNotSame($right->content[1], $result->content[3]);
    }

    public function testMappingAdditionHandsOverTheReplacedRightSide(): void
    {
        [$left, $right] = self::mappings();
        $result         = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right, '', null, $right);

        self::assertInstanceOf(Node::class, $result);
        self::assertNotSame($left->content[0], $result->content[0]);
        self::assertSame($right->content[1], $result->content[3]);
        self::assertSame($right->content[2], $result->content[4]);
        self::assertSame($right->content[3], $result->content[5]);
    }

    public function testMappingKeysAreMatchedByValueNotPosition(): void
    {
        $left   = Node::mapping([Node::scalar('x'), Node::scalar('1'), Node::scalar('y'), Node::scalar('2'), Node::scalar('z'), Node::scalar('3')]);
        $right  = Node::mapping([Node::scalar('z'), Node::scalar('9'), Node::scalar('x'), Node::scalar('8')]);
        $result = ArithmeticOperator::apply(BinaryOperatorEnum::Add, $left, $right);

        self::assertInstanceOf(Node::class, $result);
        self::assertSame(['x', '8', 'y', '2', 'z', '9'], array_map(static fn (Node $node): string => $node->value, $result->content));
    }

    /**
     * @return Generator<string, array{BinaryOperatorEnum, Node, Node, string}>
     */
    public static function rejectionProvider(): Generator
    {
        $text = Node::scalar('a', CoreSchema::TAG_STR);
        $word = Node::scalar('b', CoreSchema::TAG_STR);
        $two  = Node::scalar('2');

        yield 'a map added to an int' => [BinaryOperatorEnum::Add, Node::scalar('1'), Node::mapping(), '!!map (map) cannot be added to a !!int (scalar)'];
        yield 'maps subtracted' => [BinaryOperatorEnum::Subtract, Node::mapping(), Node::mapping(), '!!map (map) cannot be subtracted from !!map (map)'];
        yield 'strings subtracted' => [BinaryOperatorEnum::Subtract, $text, $word, '!!str (scalar) cannot be subtracted from !!str (scalar)'];
        yield 'a sequence subtracted from an int' => [BinaryOperatorEnum::Subtract, Node::scalar('1'), Node::sequence(), '!!seq (seq) cannot be subtracted from !!int (scalar)'];
        yield 'an int divides a string' => [BinaryOperatorEnum::Divide, $text, $two, '!!str (scalar) cannot be divided by !!int (scalar)'];
        yield 'a string divides an int' => [BinaryOperatorEnum::Divide, $two, $text, '!!int (scalar) cannot be divided by !!str (scalar)'];
        yield 'maps divided' => [BinaryOperatorEnum::Divide, Node::mapping(), Node::mapping(), '!!map (map) cannot be divided by !!map (map)'];
        yield 'a string modded by an int' => [BinaryOperatorEnum::Modulo, $text, $two, '!!str (scalar) cannot be modded by !!int (scalar)'];
        yield 'an int modded by a string' => [BinaryOperatorEnum::Modulo, $two, $text, '!!int (scalar) cannot be modded by !!str (scalar)'];
    }

    #[DataProvider('rejectionProvider')]
    public function testRejectsWhatCannotBeCombined(BinaryOperatorEnum $operator, Node $left, Node $right, string $message): void
    {
        self::assertRejected($message, static fn (): ?Node => ArithmeticOperator::apply($operator, $left, $right));
    }

    public function testMultiplicationMergesContainersAndPrefersNumbersOverRepetition(): void
    {
        $merged = ArithmeticOperator::apply(
            BinaryOperatorEnum::Multiply,
            Node::mapping([Node::scalar('x'), Node::scalar('1')]),
            Node::mapping([Node::scalar('y'), Node::scalar('2')]),
        );
        self::assertInstanceOf(Node::class, $merged);
        self::assertSame(['x', '1', 'y', '2'], array_map(static fn (Node $node): string => $node->value, $merged->content));

        $numbers = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('3', CoreSchema::TAG_STR), Node::scalar('4'));
        self::assertInstanceOf(Node::class, $numbers);
        self::assertSame('3333', $numbers->value);
    }

    public function testRepetitionNeedsAStringOnTheTextSide(): void
    {
        $repeated = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('ab', CoreSchema::TAG_STR), Node::scalar('2'));
        self::assertInstanceOf(Node::class, $repeated);
        self::assertSame(self::REPEATED, $repeated->value);

        $swapped = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('2'), Node::scalar('ab', CoreSchema::TAG_STR));
        self::assertInstanceOf(Node::class, $swapped);
        self::assertSame(self::REPEATED, $swapped->value);

        $scalarOverMap = ArithmeticOperator::apply(BinaryOperatorEnum::Multiply, Node::scalar('ab', CoreSchema::TAG_STR), Node::scalar('x', CoreSchema::TAG_STR));
        self::assertInstanceOf(Node::class, $scalarOverMap);
        self::assertSame('x', $scalarOverMap->value);
    }

    public function testDivisionSplitsStrings(): void
    {
        $parts = ArithmeticOperator::apply(BinaryOperatorEnum::Divide, Node::scalar('a-b-c', CoreSchema::TAG_STR), Node::scalar('-', CoreSchema::TAG_STR));

        self::assertInstanceOf(Node::class, $parts);
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Node $node): string => $node->value, $parts->content));
    }
}
