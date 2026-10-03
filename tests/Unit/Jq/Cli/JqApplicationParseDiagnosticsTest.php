<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ParseDiagnostics;
use LTS\PhpXq\Jq\Cli\UsageText;
use LTS\PhpXq\Json\JsonDecoder;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationParseDiagnosticsTest extends TestCase
{
    public function testMessageHasWholeTextPositions(): void
    {
        $diagnostics = new ParseDiagnostics(new JsonDecoder());
        $text        = "1\n2\n  foo 3";

        self::assertSame('Invalid literal at line 3, column 6', $diagnostics->message($text, 6));
    }

    public function testMessageFromTheStartOfTheText(): void
    {
        $diagnostics = new ParseDiagnostics(new JsonDecoder());

        self::assertSame('Invalid literal at EOF at line 1, column 3', $diagnostics->message('foo', 0));
    }

    public function testFallbackWhenTheDecoderFindsNothingWrong(): void
    {
        $diagnostics = new ParseDiagnostics(new JsonDecoder());

        self::assertSame('Invalid JSON text', $diagnostics->message('1 2', 0));
    }

    public function testPosition(): void
    {
        self::assertSame('line 1, column 0', ParseDiagnostics::position('abc', 0));
        self::assertSame('line 1, column 3', ParseDiagnostics::position('abc', 3));
        self::assertSame('line 2, column 0', ParseDiagnostics::position("ab\ncd", 3));
        self::assertSame('line 2, column 2', ParseDiagnostics::position("ab\ncd", 5));
        self::assertSame('line 3, column 0', ParseDiagnostics::position("a\n\n", 3));
    }

    public function testUsageTexts(): void
    {
        self::assertStringStartsWith("Usage:\tjq [OPTIONS] FILTER [FILES...]\n\tjq [OPTIONS] --args FILTER [STRINGS...]\n", UsageText::short());
        self::assertStringEndsWith("or see the jq manpage, or online docs  at https://jqlang.org\n", UsageText::short());
        self::assertStringStartsWith('Use jq --help', UsageText::hint());
        self::assertStringStartsWith("Usage:\tjq [OPTIONS] FILTER [FILES...]\n", UsageText::full());
        self::assertStringContainsString("\t\$ echo '{\"foo\": 0}' | jq .\n", UsageText::full());
        self::assertStringContainsString('  -e, --exit-status         set exit status code based on the output;', UsageText::full());
        self::assertSame("jq-1.8.2\n", UsageText::version());
        self::assertStringContainsString("\n", UsageText::buildConfiguration());
    }
}
