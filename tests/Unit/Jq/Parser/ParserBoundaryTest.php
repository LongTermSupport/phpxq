<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Error annotation and constant folding corners of the parser.
 *
 * @internal
 */
final class ParserBoundaryTest extends TestCase
{
    private const string UNEXPECTED_CATCH = "syntax error, unexpected catch, expecting end or '|' or ',' at <top-level>, line 1, column ";

    private const string IF_NOTE = "\njq: error: Possibly unterminated 'if' statement at <top-level>, line 1, column ";

    private const string TRY_NOTE = "\njq: error: Possibly unterminated 'try' statement at <top-level>, line 1, column ";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function annotationProvider(): iterable
    {
        yield 'catch at end of input' => [
            'try if . then 1 else 2 catch',
            self::UNEXPECTED_CATCH . '24:' . self::IF_NOTE . '5:' . self::TRY_NOTE . '1:',
        ];
        yield 'catch before a closing bracket then more text' => [
            '[try if . then 1 else 2 catch ] 3',
            self::UNEXPECTED_CATCH . '25:' . self::IF_NOTE . '6:' . self::TRY_NOTE . '2:',
        ];
        yield 'try body fails without an unterminated if' => [
            '[try catch]',
            'syntax error, unexpected catch at <top-level>, line 1, column 6:',
        ];
        yield 'unterminated if but no catch' => [
            '[try if . then 1 ]',
            "syntax error, unexpected ']' at <top-level>, line 1, column 18:" . self::IF_NOTE . '6:',
        ];
    }

    #[DataProvider('annotationProvider')]
    public function testUnterminatedAnnotations(string $source, string $message): void
    {
        try {
            $this->parser()->parse($source);
            self::fail('expected a compile error for ' . $source);
        } catch (JqCompileException $jqCompileException) {
            self::assertSame($message, $jqCompileException->getMessage());
        }
    }

    public function testObjectKeyThatRunsIntoEndOfInputIsNotAColonProblem(): void
    {
        try {
            $this->parser()->parse('{1 + 2');
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertSame('syntax error, unexpected LITERAL at <top-level>, line 1, column 2:', $jqCompileException->getMessage());
        }
    }

    public function testModuleMetadataListsFlattenNestedCommas(): void
    {
        $program = $this->parser()->parse('module {list: ["a", ("b", "c"), "d"]}; 0');

        self::assertNotNull($program->module);
        self::assertEquals(new JsonObject(['list' => ['a', 'b', 'c', 'd']]), $program->module->metadata);
    }

    private function parser(): Parser
    {
        return new Parser(new Lexer());
    }
}
