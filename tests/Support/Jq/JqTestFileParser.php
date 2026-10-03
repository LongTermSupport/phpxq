<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Jq;

use InvalidArgumentException;

/**
 * Parses the line oriented `.test` format used by jq's `--run-tests` (see jq's src/jq_test.c).
 *
 * Blank lines and lines starting with `#` (after optional spaces or tabs) separate cases. A case is an
 * optional `%%FAIL` or `%%FAIL IGNORE MSG` marker, a program line, then either an input line followed by
 * expected output lines, or, for a failing case, the expected error message lines.
 */
final class JqTestFileParser
{
    private const string FAIL_MARKER             = '%%FAIL';

    private const string FAIL_IGNORE_MSG_MARKER = '%%FAIL IGNORE MSG';

    /**
     * @return list<JqTestCase>
     */
    public function parseFile(string $path): array
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new InvalidArgumentException('Cannot read jq test file ' . $path);
        }

        return $this->parseString($contents, basename($path));
    }

    /**
     * @return list<JqTestCase>
     */
    public function parseString(string $contents, string $sourceFile): array
    {
        $lines = explode("\n", $contents);
        if ('' === end($lines)) {
            array_pop($lines);
        }

        $cases = [];
        $count = \count($lines);
        $index = 0;
        while ($index < $count) {
            if ($this->isSkipLine($lines[$index])) {
                ++$index;

                continue;
            }

            $shouldFail        = false;
            $failIgnoreMessage = false;
            if (self::FAIL_MARKER === $lines[$index] || self::FAIL_IGNORE_MSG_MARKER === $lines[$index]) {
                $shouldFail        = true;
                $failIgnoreMessage = self::FAIL_IGNORE_MSG_MARKER === $lines[$index];
                ++$index;
            }

            if ($index >= $count) {
                throw new InvalidArgumentException($sourceFile . ': %%FAIL marker without a program');
            }

            $program     = $lines[$index];
            $programLine = $index + 1;
            ++$index;

            $input = '';
            if (!$shouldFail) {
                if ($index >= $count) {
                    throw new InvalidArgumentException($sourceFile . ':' . $programLine . ': program without an input line');
                }

                $input = $lines[$index];
                ++$index;
            }

            $trailing = [];
            while ($index < $count && !$this->isSkipLine($lines[$index])) {
                $trailing[] = $lines[$index];
                ++$index;
            }

            $cases[] = new JqTestCase(
                $program,
                $input,
                $shouldFail ? [] : $trailing,
                $shouldFail,
                $failIgnoreMessage,
                $shouldFail ? $trailing : [],
                $sourceFile,
                $programLine,
            );
        }

        return $cases;
    }

    private function isSkipLine(string $line): bool
    {
        $stripped = ltrim($line, " \t");

        return '' === $stripped || str_starts_with($stripped, '#');
    }
}
