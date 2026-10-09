<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The hidden `__complete` command the generated completion scripts call. It prints one candidate per
 * line (`name<TAB>description`), then `:<directive>`, and reports the directive on stderr, as cobra does.
 *
 * @internal
 */
final class CompleteCommand
{
    public const int DIRECTIVE_DEFAULT = 0;

    public const int DIRECTIVE_NO_FILE_COMP = 4;

    private const array DESCRIPTIONS = [
        'completion' => 'Generate the autocompletion script for the specified shell',
        'eval'       => '(default) Apply the expression to each document in each yaml file in sequence',
        'eval-all'   => 'Loads _all_ yaml documents of _all_ yaml files and runs expression once',
        'help'       => 'Help about any command',
    ];

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @param string   ...$words everything after `__complete`; the last word is the one being completed
     */
    public function run(bool $withDescriptions, mixed $stdout, mixed $stderr, string ...$words): int
    {
        $toComplete = [] === $words ? '' : array_last($words);
        $previous   = \array_slice($words, 0, max(0, \count($words) - 1));

        $candidates = $this->candidates($toComplete, ...$previous);
        $directive  = [] === $candidates ? self::DIRECTIVE_DEFAULT : self::DIRECTIVE_NO_FILE_COMP;

        foreach ($candidates as $name => $description) {
            fwrite($stdout, $withDescriptions && '' !== $description ? $name . "\t" . $description . "\n" : $name . "\n");
        }

        fwrite($stdout, ':' . $directive . "\n");
        fwrite($stderr, 'Completion ended with directive: ' . $this->directiveName($directive) . "\n");

        return YqApplicationInterface::EXIT_OK;
    }

    /**
     * @return array<string, string> candidate to description
     */
    private function candidates(string $toComplete, string ...$previous): array
    {
        if (str_starts_with($toComplete, '-')) {
            return $this->flagCandidates($toComplete);
        }

        $command = '';
        $others  = 0;
        foreach ($previous as $word) {
            if (str_starts_with($word, '-')) {
                continue;
            }

            if ('' === $command && 0 === $others && \in_array($word, ArgumentParser::COMMANDS, true)) {
                $command = $word;

                continue;
            }

            ++$others;
        }

        if ('completion' === $command && 0 === $others) {
            return $this->matching(array_fill_keys(CompletionScripts::SHELLS, ''), $toComplete);
        }

        if ('' === $command && 0 === $others) {
            return $this->matching(self::DESCRIPTIONS, $toComplete);
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function flagCandidates(string $toComplete): array
    {
        $all = [];
        foreach (FlagCatalog::all() as $spec) {
            if (!$spec->hidden) {
                $all['--' . $spec->name] = $spec->usage;
            }
        }

        return $this->matching($all, $toComplete);
    }

    /**
     * @param array<string, string> $all
     *
     * @return array<string, string>
     */
    private function matching(array $all, string $prefix): array
    {
        $out = [];
        foreach ($all as $name => $description) {
            if (str_starts_with($name, $prefix)) {
                $out[$name] = $description;
            }
        }

        return $out;
    }

    private function directiveName(int $directive): string
    {
        if (self::DIRECTIVE_DEFAULT === $directive) {
            return 'ShellCompDirectiveDefault';
        }

        $names = [];
        foreach ([1 => 'Error', 2 => 'NoSpace', 4 => 'NoFileComp', 8 => 'FilterFileExt', 16 => 'FilterDirs', 32 => 'KeepOrder'] as $bit => $name) {
            if (0 !== ($directive & $bit)) {
                $names[] = 'ShellCompDirective' . $name;
            }
        }

        return implode(', ', $names);
    }
}
