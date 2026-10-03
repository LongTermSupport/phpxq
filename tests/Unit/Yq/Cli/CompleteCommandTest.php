<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\CompleteCommand;
use LTS\PhpXq\Yq\Cli\CompletionScripts;
use LTS\PhpXq\Yq\Cli\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CompleteCommandTest extends TestCase
{
    /**
     * @param list<string> $words
     *
     * @return array{string, string}
     */
    private function complete(array $words, bool $descriptions = true): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);

        new CompleteCommand()->run($words, $descriptions, $out, $err);
        rewind($out);
        rewind($err);

        return [(string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testSubCommandsAreOfferedFirst(): void
    {
        [$out, $err] = $this->complete(['']);

        self::assertStringContainsString("completion\tGenerate the autocompletion script", $out);
        self::assertStringContainsString("help\tHelp about any command", $out);
        self::assertStringEndsWith(":4\n", $out);
        self::assertSame("Completion ended with directive: ShellCompDirectiveNoFileComp\n", $err);
    }

    public function testSubCommandsFilterByPrefix(): void
    {
        [$out] = $this->complete(['eval-']);

        self::assertStringStartsWith("eval-all\t", $out);
        self::assertStringNotContainsString("completion\t", $out);
    }

    public function testNoDescriptionVariant(): void
    {
        [$out] = $this->complete(['eval-'], false);

        self::assertSame("eval-all\n:4\n", $out);
    }

    public function testFlagsAreOfferedForADashedWord(): void
    {
        [$out] = $this->complete(['--null']);

        self::assertStringContainsString("--null-input\t", $out);
        self::assertStringNotContainsString('--tojson', $out);
    }

    public function testShellsAreOfferedForCompletion(): void
    {
        [$out] = $this->complete(['completion', '']);

        self::assertSame("bash\nzsh\nfish\npowershell\n:4\n", $out);
    }

    public function testNothingIsOfferedAfterAnExpression(): void
    {
        [$out, $err] = $this->complete(['.a', '']);

        self::assertSame(":0\n", $out);
        self::assertSame("Completion ended with directive: ShellCompDirectiveDefault\n", $err);
    }

    public function testEveryShellHasAScriptThatCallsBack(): void
    {
        foreach (CompletionScripts::SHELLS as $shell) {
            self::assertStringContainsString('__complete', CompletionScripts::forShell($shell), $shell);
        }

        self::assertSame('', CompletionScripts::forShell('nushell'));
    }

    public function testHelpTextForSubCommands(): void
    {
        self::assertStringContainsString('yq eval [expression] [yaml_file1]... [flags]', HelpText::forCommand('eval'));
        self::assertStringContainsString('eval-all, ea', HelpText::forCommand('ea'));
        self::assertStringContainsString('yq completion [command]', HelpText::forCommand('completion'));
        self::assertSame(HelpText::root(), HelpText::forCommand('nonsense'));
    }
}
