<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression\Parser;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Expression\AstDumper;
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\Parser\PrattParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Binding power and associativity of every binary operator, and the exact offsets and messages of syntax errors.
 *
 * @internal
 */
#[CoversClass(PrattParser::class)]
final class PrattParserPrecedenceTest extends TestCase
{
    private const string GENERIC = 'Bad expression, please check expression syntax';

    private const string MISSING_PAREN = 'Bad expression, could not find matching `)`';

    private const string MISSING_BRACKET = 'Bad expression, could not find matching `]`';

    private const string MISSING_BRACE ='Bad expression, could not find matching `}`';

    /**
     * Operators grouped from the loosest binding level to the tightest.
     *
     * @return list<list<string>>
     */
    private static function levels(): array
    {
        return [
            ['or'],
            ['and'],
            ['|'],
            [','],
            ['=', '=c', '|=', '+=', '-=', '/=', '%=', '*=', '*=c'],
            ['//'],
            ['==', '!=', '<', '<=', '>', '>='],
            ['+', '-'],
            ['*', '/', '%', '*+', '*?', '*d', '*n'],
        ];
    }

    private static function head(string $operator): string
    {
        return match ($operator) {
            '=c'    => '=/c',
            '*=c'   => '*=/c',
            '*+'    => '*/+',
            '*?'    => '*/?',
            '*d'    => '*/d',
            '*n'    => '*/n',
            default => $operator,
        };
    }

    private static function node(string $operator, string $left, string $right): string
    {
        return '(' . self::head($operator) . ' ' . $left . ' ' . $right . ')';
    }

    private static function chain(string $first, string $second): string
    {
        return \sprintf('1 %s 2 %s 3', $first, $second);
    }

    private static function number(int $value): string
    {
        return '!!int:' . $value;
    }

    private static function dump(string $source): string
    {
        return AstDumper::dump(new PrattParser(new ExpressionLexer()->tokenize($source))->parseAll());
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function adjacentLevelProvider(): Generator
    {
        $levels = self::levels();
        $one    = self::number(1);
        $two    = self::number(2);
        $three  = self::number(3);
        foreach ($levels as $index => $loose) {
            if (!isset($levels[$index + 1])) {
                break;
            }

            foreach ($loose as $x) {
                foreach ($levels[$index + 1] as $y) {
                    yield \sprintf('loose first %s then %s', $x, $y) => [
                        self::chain($x, $y),
                        self::node($x, $one, self::node($y, $two, $three)),
                    ];

                    yield \sprintf('tight first %s then %s', $y, $x) => [
                        self::chain($y, $x),
                        self::node($x, self::node($y, $one, $two), $three),
                    ];
                }
            }
        }
    }

    #[DataProvider('adjacentLevelProvider')]
    public function testTighterOperatorsBindFirst(string $source, string $expected): void
    {
        self::assertSame($expected, self::dump($source));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function associativityProvider(): Generator
    {
        $one   = self::number(1);
        $two   = self::number(2);
        $three = self::number(3);
        foreach (self::levels() as $index => $level) {
            $rightAssociative = 4 === $index;
            foreach ($level as $x) {
                yield 'chain of ' . $x => [
                    self::chain($x, $x),
                    $rightAssociative
                        ? self::node($x, $one, self::node($x, $two, $three))
                        : self::node($x, self::node($x, $one, $two), $three),
                ];

                foreach ($level as $y) {
                    if ($x === $y) {
                        continue;
                    }

                    yield \sprintf('mixed %s then %s', $x, $y) => [
                        self::chain($x, $y),
                        $rightAssociative
                            ? self::node($x, $one, self::node($y, $two, $three))
                            : self::node($y, self::node($x, $one, $two), $three),
                    ];
                }
            }
        }
    }

    #[DataProvider('associativityProvider')]
    public function testOperatorsGroupByAssociativity(string $source, string $expected): void
    {
        self::assertSame($expected, self::dump($source));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function looseOperatorInsideProvider(): Generator
    {
        $either = '(or (field . !!str:a) (field . !!str:b))';

        yield 'parens'           => ['(.a or .b)', $either];
        yield 'collect'          => ['[.a or .b]', '(collect ' . $either . ')'];
        yield 'call argument'    => ['f(.a or .b)', '(call f ' . $either . ')'];
        yield 'second argument'  => ['f(1; .a or .b)', '(call f !!int:1 ' . $either . ')'];
        yield 'bracket index'    => ['.[.a or .b]', '(field . ' . $either . ')'];
        yield 'slice start'      => ['.[.a or .b:]', '(slice . ' . $either . ' _)'];
        yield 'slice end'        => ['.[1:.a or .b]', '(slice . !!int:1 ' . $either . ')'];
        yield 'if condition'     => ['if .a or .b then 1 end', '(if ' . $either . ' !!int:1 _)'];
        yield 'if branch'        => ['if 1 then .a or .b end', '(if !!int:1 ' . $either . ' _)'];
        yield 'else branch'      => ['if 1 then 2 else .a or .b end', '(if !!int:1 !!int:2 ' . $either . ')'];
        yield 'object value'     => ['{"k": .a or .b}', '(obj [!!str:k ' . $either . '])'];
        yield 'object paren key' => ['{(.a or .b): 1}', '(obj [' . $either . ' !!int:1])'];
        yield 'as body'          => ['1 as $x | .a or .b', '(as $x !!int:1 ' . $either . ')'];
        yield 'ref body'         => ['1 ref $x | .a or .b', '(ref $x !!int:1 ' . $either . ')'];
        yield 'reduce initial'   => ['reduce .[] as $x (.a or .b; 1)', '(reduce $x (iter .) ' . $either . ' !!int:1)'];
        yield 'reduce update'    => ['reduce .[] as $x (0; .a or .b)', '(reduce $x (iter .) !!int:0 ' . $either . ')'];
        yield 'interpolation'    => ['"\(.a or .b)"', '(interp ' . $either . ')'];
    }

    #[DataProvider('looseOperatorInsideProvider')]
    public function testLowestPrecedenceOperatorsAreParsedInsideEveryGroup(string $source, string $expected): void
    {
        self::assertSame($expected, self::dump($source));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function unionStateProvider(): Generator
    {
        yield 'union inside paren value'      => ['{a: (1, 2), b: 3}', '(obj [!!str:a (, !!int:1 !!int:2)] [!!str:b !!int:3])'];
        yield 'union inside collect value'    => ['{a: [1, 2], b: 3}', '(obj [!!str:a (collect (, !!int:1 !!int:2))] [!!str:b !!int:3])'];
        yield 'union inside call value'       => ['{a: f(1, 2), b: 3}', '(obj [!!str:a (call f (, !!int:1 !!int:2))] [!!str:b !!int:3])'];
        yield 'union after object'            => ['{a: 1}, .b', '(, (obj [!!str:a !!int:1]) (field . !!str:b))'];
        yield 'union after nested object'     => ['{a: {b: 1}, c: 2}, 3', '(, (obj [!!str:a (obj [!!str:b !!int:1])] [!!str:c !!int:2]) !!int:3)'];
        yield 'union after object in bracket' => ['[{a: 1}, 2]', '(collect (, (obj [!!str:a !!int:1]) !!int:2))'];
        yield 'object in object in parens'    => ['({a: 1}, {b: 2})', '(, (obj [!!str:a !!int:1]) (obj [!!str:b !!int:2]))'];
        yield 'pipe in object value'          => ['{a: 1 | 2, b: 3}', '(obj [!!str:a (| !!int:1 !!int:2)] [!!str:b !!int:3])'];
        yield 'assign in object value'        => ['{a: .x = 1, b: 2}', '(obj [!!str:a (= (field . !!str:x) !!int:1)] [!!str:b !!int:2])'];
    }

    #[DataProvider('unionStateProvider')]
    public function testCommaMeaningIsRestoredAfterNestedGroups(string $source, string $expected): void
    {
        self::assertSame($expected, self::dump($source));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function structureProvider(): Generator
    {
        yield 'recursive optional'       => ['..?', '(rec .)'];
        yield 'recursive keys optional'  => ['...?', '(recall .)'];
        yield 'recursive double optional' => ['..??', '(rec .)'];
        yield 'quoted dot after pipe'    => ['.a | ."b c"', '(| (field . !!str:a) (field . !!str:b c))'];
        yield 'dot word after pipe'      => ['.a | .b', '(| (field . !!str:a) (field . !!str:b))'];
        yield 'dot after union'          => ['.a, ."b"', '(, (field . !!str:a) (field . !!str:b))'];
        yield 'quoted postfix twice'     => ['.a."b"."c"', '(field (field (field . !!str:a) !!str:b) !!str:c)'];
        yield 'bracket postfix after name' => ['.a.b.["c"]', '(field (field (field . !!str:a) !!str:b) !!str:c)'];
        yield 'optional postfix chain'   => ['.a?.b?', '(field? (field? . !!str:a) !!str:b)'];
        yield 'double optional'          => ['.a??', '(field? . !!str:a)'];
        yield 'optional after iterate'   => ['.[]??', '(iter? .)'];
        yield 'single quoted keeps backslash paren' => ["'a\\(.x)'", '!!str:a\(.x)'];
        yield 'single quoted keeps escape' => ["'a\\nb'", '!!str:a\nb'];
        yield 'plain string unescapes'   => ['"a\tb"', "!!str:a\tb"];
        yield 'interpolation tail'       => ['"x\(.a)"', "(interp 'x' (field . !!str:a))"];
        yield 'two interpolations'       => ['"\(.a)\(.b)"', '(interp (field . !!str:a) (field . !!str:b))'];
        yield 'dot separated by space'   => ['. a', '(| . (call a))'];
        yield 'dot then space then name' => ['.  .a', '(field . !!str:a)'];
        yield 'ref as a plain word'      => ['.a ref', '(| (field . !!str:a) (call ref))'];
        yield 'ref word then pipe'       => ['.a ref | .b', '(| (| (field . !!str:a) (call ref)) (field . !!str:b))'];
        yield 'as binds the rest'        => ['.a as $x | $x, 1', '(as $x (field . !!str:a) (, $x !!int:1))'];
        yield 'ireduce initial union'    => ['.a as $x ireduce (1, 2; 3)', '(reduce $x (field . !!str:a) (, !!int:1 !!int:2) !!int:3)'];
        yield 'prefix reduce keeps bind off' => ['reduce .a as $x (0; $x)', '(reduce $x (field . !!str:a) !!int:0 $x)'];
        yield 'object entries keep order' => ['{b: 1, a: 2}', '(obj [!!str:b !!int:1] [!!str:a !!int:2])'];
        yield 'shorthand then pair'      => ['{a, b: 1}', '(obj [!!str:a (field . !!str:a)] [!!str:b !!int:1])'];
        yield 'quoted shorthand'         => ['{"a"}', '(obj [!!str:a (field . !!str:a)])'];
        yield 'quoted key pair'          => ['{"a": 1}', '(obj [!!str:a !!int:1])'];
        yield 'interpolated key'         => ['{"a\(.b)": 1}', "(obj [(interp 'a' (field . !!str:b)) !!int:1])"];
        yield 'negative number'          => ['-1', '!!int:-1'];
        yield 'subtract negative'        => ['1 - -1', '(- !!int:1 !!int:-1)'];
        yield 'negative in call'         => ['f(-2)', '(call f !!int:-2)'];
    }

    #[DataProvider('structureProvider')]
    public function testStructures(string $source, string $expected): void
    {
        self::assertSame($expected, self::dump($source));
    }

    /**
     * @return Generator<string, array{string, string, int}>
     */
    public static function errorProvider(): Generator
    {
        yield 'stray closer'            => ['.a)', self::GENERIC, 2];
        yield 'unclosed paren'          => ['(.a', self::MISSING_PAREN, 3];
        yield 'unclosed call'           => ['f(.a', self::MISSING_PAREN, 4];
        yield 'unclosed second argument' => ['f(1; 2', self::MISSING_PAREN, 6];
        yield 'reserved word key'       => ['{if: 1}', self::GENERIC, 3];
        yield 'unclosed bracket'        => ['.a[0', self::MISSING_BRACKET, 4];
        yield 'unclosed collect'        => ['[.a', self::MISSING_BRACKET, 3];
        yield 'unclosed slice'          => ['.[1:2', self::MISSING_BRACKET, 5];
        yield 'unclosed object'         => ['{a: 1', self::MISSING_BRACE, 5];
        yield 'object missing colon'    => ['{1 2}', self::MISSING_BRACE, 3];
        yield 'if without then'         => ['if .a else 1 end', self::GENERIC, 6];
        yield 'if without end'          => ['if .a then 1', self::GENERIC, 12];
        yield 'ref with ireduce'        => ['.a ref $x ireduce (0; 1)', self::GENERIC, 10];
        yield 'bind then operator'      => ['.a as $x + 1', self::GENERIC, 9];
        yield 'bind then update'        => ['.a as $x |= 1', self::GENERIC, 9];
        yield 'bind then end'           => ['.a as $x', self::GENERIC, 8];
        yield 'bind without variable'   => ['.a as 1 | 2', self::GENERIC, 6];
        yield 'object key bind'         => ['{.a as $x: 1}', self::MISSING_BRACE, 4];
        yield 'reduce without paren'    => ['reduce .a as $x 0', 'Bad expression, could not find matching `(`', 16];
        yield 'reduce without semicolon' => ['reduce .a as $x (0)', self::GENERIC, 18];
        yield 'reduce without close'    => ['reduce .a as $x (0; 1', self::MISSING_PAREN, 21];
        yield 'ireduce without paren'   => ['.a as $x ireduce 1', 'Bad expression, could not find matching `(`', 17];
        yield 'reduce without as'       => ['reduce .a (0; 1)', self::GENERIC, 10];
        yield 'reduce without variable' => ['reduce .a as 1 (0; 1)', self::GENERIC, 13];
        yield 'reserved word'           => ['then', self::GENERIC, 0];
        yield 'dangling operator'       => ['.a +', self::GENERIC, 4];
        yield 'bare minus'              => ['-', self::GENERIC, 0];
        yield 'leading operator'        => ['| .a', self::GENERIC, 0];
        yield 'bad interpolation'       => ['.a | "xy \(.b +)"', self::GENERIC, 15];
        yield 'bad nested interpolation' => ['"\(.a +)"', self::GENERIC, 7];
        yield 'bad interpolation after text' => ['"abc \(.a +)" | 1', self::GENERIC, 11];
    }

    #[DataProvider('errorProvider')]
    public function testErrorsCarryTheMessageAndByteOffset(string $source, string $message, int $offset): void
    {
        try {
            self::dump($source);
            self::fail('a syntax error was expected');
        } catch (ExpressionSyntaxException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($offset, $exception->offset);
        }
    }
}
