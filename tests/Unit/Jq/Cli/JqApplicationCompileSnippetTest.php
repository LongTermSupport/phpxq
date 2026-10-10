<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\JqExitCode;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The source line and carets printed under a compile error from the real parser, and the exit status of `-e`
 * with `-n`.
 *
 * @internal
 */
final class JqApplicationCompileSnippetTest extends TestCase
{
    private const string IF_CLOSED_LATE = "    if 1 then 2   \n";

    private const string STRICT = '-e .';

    #[DataProvider('snippets')]
    public function testSnippet(string $source, string $expected): void
    {
        $err = $this->memory();

        $status = $this->application(static function (): void {
        })->run($this->memory(), $this->memory(), $err, '-n', $source);

        self::assertSame(JqExitCode::COMPILE_ERROR, $status);
        self::assertSame($expected, $this->contents($err));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snippets(): iterable
    {
        yield 'a word is underlined whole' => [
            'foo bar',
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 1, column 5:\n    foo bar\n        ^^^\njq: 1 compile error\n",
        ];
        yield 'a punctuation mark is one caret' => [
            '. | | .',
            "jq: error: syntax error, unexpected '|' at <top-level>, line 1, column 5:\n    . | | .\n        ^\njq: 1 compile error\n",
        ];
        yield 'a word after spaces' => [
            'a  bcd',
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 1, column 4:\n    a  bcd\n       ^^^\njq: 1 compile error\n",
        ];
        yield 'the end of the program is one caret past the text' => [
            '1 +',
            "jq: error: syntax error, unexpected end of file at <top-level>, line 1, column 4:\n    1 +\n       ^\njq: 1 compile error\n",
        ];
        yield 'an unterminated construct is underlined to the end of the line' => [
            'if 1 then 2   ',
            "jq: error: syntax error, unexpected end of file at <top-level>, line 1, column 15:\n" . self::IF_CLOSED_LATE
                . "                  ^\n"
                . "jq: error: Possibly unterminated 'if' statement at <top-level>, line 1, column 1:\n" . self::IF_CLOSED_LATE
                . "    ^^^^^^^^^^^\njq: 2 compile errors\n",
        ];
        yield 'an unterminated construct from the middle of the line' => [
            '.[] | if . then 1 else',
            "jq: error: syntax error, unexpected end of file at <top-level>, line 1, column 23:\n    .[] | if . then 1 else\n                          ^\n"
                . "jq: error: Possibly unterminated 'if' statement at <top-level>, line 1, column 7:\n    .[] | if . then 1 else\n          ^^^^^^^^^^^^^^^^\n"
                . "jq: 2 compile errors\n",
        ];
        yield 'the second line' => [
            "first\nsecond )",
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 2, column 1:\n    second )\n    ^^^^^^\njq: 1 compile error\n",
        ];
        yield 'an indented word on the second line' => [
            "x\n  y z )",
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 2, column 3:\n      y z )\n      ^\njq: 1 compile error\n",
        ];
        yield 'the third line' => [
            "ab\n\n  cd )",
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 3, column 3:\n      cd )\n      ^^\njq: 1 compile error\n",
        ];
        yield 'carriage returns are dropped from the printed line' => [
            "foo bar\r\nx",
            "jq: error: syntax error, unexpected IDENT at <top-level>, line 1, column 5:\n    foo bar\n        ^^^\njq: 1 compile error\n",
        ];
    }

    public function testNullInputWithETrueOutputExitsZero(): void
    {
        self::assertSame(JqExitCode::OK, $this->exitStatus(self::STRICT, true));
    }

    public function testNullInputWithEFalseOutputExitsOne(): void
    {
        self::assertSame(JqExitCode::LAST_OUTPUT_FALSY, $this->exitStatus(self::STRICT, false));
    }

    public function testNullInputWithENullOutputExitsOne(): void
    {
        self::assertSame(JqExitCode::LAST_OUTPUT_FALSY, $this->exitStatus(self::STRICT, null));
    }

    public function testNullInputWithENoOutputExitsFour(): void
    {
        $status = $this->application(static function (): void {
        })->run($this->memory(), $this->memory(), $this->memory(), '-n', '-e', '.');

        self::assertSame(JqExitCode::NO_OUTPUT, $status);
    }

    public function testNullInputWithoutEIgnoresTheOutputValue(): void
    {
        self::assertSame(JqExitCode::OK, $this->exitStatus('.', false));
    }

    /**
     * @param Closure(RuntimeContextInterface, mixed, Closure(mixed): void): void|Closure(): void $behaviour
     */
    private function application(Closure $behaviour): JqApplication
    {
        return new JqApplication(new Parser(new Lexer()), new JqApplicationFakeCompiler($behaviour), new JsonDecoder(), new JsonEncoder());
    }

    private function exitStatus(string $flags, ?bool $output): int
    {
        return $this->application(static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use ($output): void {
            $emit($output);
        })->run($this->memory(), $this->memory(), $this->memory(), '-n', ...explode(' ', $flags));
    }

    /**
     * @return resource
     */
    private function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('no memory stream');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }
}
