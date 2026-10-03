<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

/**
 * Turns the documented examples of one yq markdown doc page into conformance cases.
 *
 * The docs follow a fixed pattern: a heading, optional "Given a X file of:" fenced input blocks, a
 * fenced bash block holding a `yq` command, and a "will output" fenced block holding stdout. Anything
 * that does not fit that pattern unambiguously becomes a YqSkip with a reason, never a silent drop.
 */
final class YqDocExtractor
{
    private const array SUBCOMMANDS = ['eval', 'e', 'eval-all', 'ea'];

    /** Flags whose value is the next word when not written as `--flag=value`. */
    private const array VALUE_FLAGS = ['-o', '-p', '-I', '--output-format', '--input-format', '--indent'];

    private const string FILE_PROSE = '/^(?:Given|And)\b.*?\'?([\w.\/-]+\.\w+)\'?\s+(?:expression\s+)?file of:\s*$/';

    private const string HEADING = '/^#{1,6}\s+(.*?)\s*$/';

    public function extract(string $markdown, string $source): YqExtraction
    {
        $cases   = [];
        $skipped = [];
        $names   = [];
        $heading = '';
        $files   = [];
        $pending = null;
        $prose   = '';

        foreach ($this->tokenise($markdown) as $token) {
            if ('heading' === $token['type']) {
                if (null !== $pending) {
                    $skipped[] = $this->noOutput($source, $pending);
                    $pending   = null;
                }

                $heading = $token['text'];
                $files   = [];
                $prose   = '';

                continue;
            }

            if ('prose' === $token['type']) {
                $prose = $token['text'];

                continue;
            }

            $block = $token['text'];
            $lang  = $token['lang'];

            if (1 === preg_match(self::FILE_PROSE, $prose, $match)) {
                $files[$match[1]] = $block;
            } elseif ('will output' === $prose && null !== $pending) {
                $outcome = $this->build($pending['command'], $block, $files);
                if (\is_string($outcome)) {
                    $skipped[] = new YqSkip($source, $pending['heading'], $outcome, trim($pending['command']));
                } else {
                    $cases[] = $this->named($outcome, $source, $pending['heading'], $names);
                }

                $pending = null;
            } elseif ('bash' === $lang || 'sh' === $lang) {
                if (null !== $pending) {
                    $skipped[] = $this->noOutput($source, $pending);
                }

                $pending = ['heading' => $heading, 'command' => $block];
                if ($this->isForeignCommand($block)) {
                    $skipped[] = new YqSkip($source, $heading, 'not a yq invocation', trim($block));
                    $pending   = null;
                }
            } else {
                $skipped[] = new YqSkip($source, $heading, 'code block outside a given/then/output group', $this->firstLine($block));
            }

            $prose = '';
        }

        if (null !== $pending) {
            $skipped[] = $this->noOutput($source, $pending);
        }

        return new YqExtraction($cases, $skipped);
    }

    /**
     * @return list<array{type: string, text: string, lang: string}>
     */
    private function tokenise(string $markdown): array
    {
        $tokens = [];
        $inside = false;
        $lang   = '';
        $buffer = [];

        foreach (explode("\n", $markdown) as $rawLine) {
            $line = rtrim($rawLine, "\r");

            if ($inside) {
                if (str_starts_with($line, '```')) {
                    $tokens[] = ['type' => 'block', 'text' => implode("\n", $buffer), 'lang' => $lang];
                    $inside   = false;

                    continue;
                }

                $buffer[] = $line;

                continue;
            }

            if (str_starts_with($line, '```')) {
                $inside = true;
                $lang   = trim(substr($line, 3));
                $buffer = [];

                continue;
            }

            if (1 === preg_match(self::HEADING, $line, $match)) {
                $tokens[] = ['type' => 'heading', 'text' => $match[1], 'lang' => ''];

                continue;
            }

            if ('' !== trim($line)) {
                $tokens[] = ['type' => 'prose', 'text' => trim($line), 'lang' => ''];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, string> $files input files given in the current section, by name
     *
     * @return array{command: string|null, flags: list<string>, expression: string|null, input: string, expected: string}|string a skip reason when it is a string
     */
    private function build(string $command, string $output, array $files): array|string
    {
        $words = new ShellWords()->split($command);
        if (null === $words) {
            return 'shell syntax the extractor cannot represent (pipe, redirect, substitution or several statements)';
        }

        $first = $words[0] ?? '';
        if (1 === preg_match('/^\w+=/', $first)) {
            return 'needs environment variables set, which the CLI runner cannot supply';
        }

        if ('yq' !== $first) {
            return 'not a yq invocation';
        }

        if (str_starts_with($output, 'Error')) {
            return 'documented output is an error message (stderr and exit code), not stdout';
        }

        $parsed = $this->parseArguments(\array_slice($words, 1), $files);
        if (\is_string($parsed)) {
            return $parsed;
        }

        return [
            'command'    => $parsed['command'],
            'flags'      => $parsed['flags'],
            'expression' => $parsed['expression'],
            'input'      => $parsed['input'],
            'expected'   => '' === $output ? '' : $output . "\n",
        ];
    }

    /**
     * @param list<string>          $words
     * @param array<string, string> $files
     *
     * @return array{command: string|null, flags: list<string>, expression: string|null, input: string}|string a skip reason when it is a string
     */
    private function parseArguments(array $words, array $files): array|string
    {
        $command    = null;
        $flags      = [];
        $expression = null;
        $fileRefs   = [];
        $unknown    = [];

        if (\in_array($words[0] ?? '', self::SUBCOMMANDS, true)) {
            $command = $words[0];
            $words   = \array_slice($words, 1);
        }

        $count = \count($words);
        for ($i = 0; $i < $count; ++$i) {
            $word = $words[$i];

            if (1 === preg_match('/^(?:-i|--inplace)(?:=|$)/', $word)) {
                return 'in-place edit (-i) writes to a file, so there is no stdout to compare';
            }

            if (1 === preg_match('/^(?:-f|--from-file|-s|--split-exp)(?:=|$)/', $word)) {
                return 'expression or output comes from a file, which the CLI runner cannot supply';
            }

            if (str_starts_with($word, '-') && '-' !== $word) {
                $flags[] = $word;
                if (\in_array($word, self::VALUE_FLAGS, true) && $i + 1 < $count) {
                    ++$i;
                    $flags[] = $words[$i];
                }

                continue;
            }

            if (\array_key_exists($word, $files)) {
                $fileRefs[$word] = true;
            } elseif (null === $expression) {
                $expression = $word;
            } else {
                $unknown[] = $word;
            }
        }

        if ([] !== $unknown) {
            return 'references input files not given in the section: ' . implode(', ', $unknown);
        }

        if (\count($fileRefs) > 1) {
            return 'several input files, which one stdin document cannot represent';
        }

        if (null !== $expression && 1 === preg_match('/\bload(?:_?str|_?xml|_?props|_?base64)?\s*\(/i', $expression)) {
            return 'expression loads files from disk (load operators)';
        }

        if (null !== $expression && 1 === preg_match('/\b(?:filename|file_?index|fileIndex)\b/', $expression)) {
            return 'depends on the input file name, which stdin does not have';
        }

        $input = '';
        foreach (array_keys($fileRefs) as $name) {
            $input = $files[$name] . "\n";
        }

        return ['command' => $command, 'flags' => $flags, 'expression' => $expression, 'input' => '' === $input || "\n" === $input ? '' : $input];
    }

    /**
     * @param array{command: string|null, flags: list<string>, expression: string|null, input: string, expected: string} $parts
     * @param array<string, int>                                                                                         $names counts of names already used, by name
     */
    private function named(array $parts, string $source, string $heading, array &$names): YqCase
    {
        $title = '' === $heading ? '(no heading)' : $heading;
        $name  = $source . ': ' . $title;
        $seen  = ($names[$name] ?? 0) + 1;

        $names[$name] = $seen;
        if ($seen > 1) {
            $name .= ' #' . $seen;
        }

        return new YqCase($name, $source, $heading, $parts['command'], $parts['flags'], $parts['expression'], $parts['input'], $parts['expected']);
    }

    /**
     * @param array{heading: string, command: string} $pending
     */
    private function noOutput(string $source, array $pending): YqSkip
    {
        return new YqSkip($source, $pending['heading'], "no 'will output' block follows the command", trim($pending['command']));
    }

    private function isForeignCommand(string $command): bool
    {
        $words = new ShellWords()->split($command);
        $first = $words[0] ?? null;

        return null !== $first && 'yq' !== $first && 0 === preg_match('/^\w+=/', $first);
    }

    private function firstLine(string $block): string
    {
        return trim(explode("\n", $block)[0]);
    }
}
