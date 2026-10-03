<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

/**
 * Splits one simple shell command into words, honouring quotes and line continuations.
 *
 * It refuses (returns null) anything that is more than one plain command: pipes, redirects, command
 * lists, substitutions, several statements, unterminated quotes. Callers record those as skipped rather
 * than guessing.
 */
final class ShellWords
{
    private const string META = '|<>;&()`';

    /**
     * @return list<string>|null
     */
    public function split(string $command): ?array
    {
        $words   = [];
        $current = '';
        $inWord  = false;
        $length  = \strlen($command);

        for ($i = 0; $i < $length; ++$i) {
            $char = $command[$i];

            if ("'" === $char) {
                $end = strpos($command, "'", $i + 1);
                if (false === $end) {
                    return null;
                }

                $current .= substr($command, $i + 1, $end - $i - 1);
                $inWord = true;
                $i      = $end;

                continue;
            }

            if ('"' === $char) {
                $parsed = $this->doubleQuoted($command, $i + 1);
                if (null === $parsed) {
                    return null;
                }

                [$text, $i] = $parsed;
                $current .= $text;
                $inWord = true;

                continue;
            }

            if ('\\' === $char) {
                if ($i + 1 >= $length) {
                    return null;
                }

                $next = $command[$i + 1];
                ++$i;
                if ("\n" === $next) {
                    continue;
                }

                $current .= $next;
                $inWord = true;

                continue;
            }

            if ("\n" === $char) {
                if ('' !== trim(substr($command, $i))) {
                    return null;
                }

                $i = $length;

                continue;
            }

            if (' ' === $char || "\t" === $char) {
                if ($inWord) {
                    $words[] = $current;
                    $current = '';
                    $inWord  = false;
                }

                continue;
            }

            if (str_contains(self::META, $char) || ('$' === $char && '(' === ($command[$i + 1] ?? ''))) {
                return null;
            }

            $current .= $char;
            $inWord = true;
        }

        if ($inWord) {
            $words[] = $current;
        }

        return $words;
    }

    /**
     * @return array{string, int}|null the unescaped text and the index of the closing quote
     */
    private function doubleQuoted(string $command, int $start): ?array
    {
        $text   = '';
        $length = \strlen($command);

        for ($i = $start; $i < $length; ++$i) {
            $char = $command[$i];

            if ('"' === $char) {
                return [$text, $i];
            }

            if ('\\' === $char && $i + 1 < $length && str_contains('"\$`', $command[$i + 1])) {
                $text .= $command[$i + 1];
                ++$i;

                continue;
            }

            if ('$' === $char && '(' === ($command[$i + 1] ?? '')) {
                return null;
            }

            $text .= $char;
        }

        return null;
    }
}
