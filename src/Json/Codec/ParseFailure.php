<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json\Codec;

use Exception;

/**
 * Internal control flow of the JSON scanner: a syntax error found after consuming $consumed bytes. The
 * scanner turns it into a {@see \LTS\PhpXq\Json\JsonSyntaxException} carrying jq's exact wording.
 *
 * @internal
 */
final class ParseFailure extends Exception
{
    /**
     * @param string $reason   jq's message without position, e.g. "Invalid numeric literal"
     * @param int    $consumed bytes of input consumed when the error was detected (jq's column counter)
     * @param bool   $eof      detected at end of input, so the position reads "at EOF at line..."
     * @param bool   $onRs     detected on an RS character (RFC 7464 mode), which needs no resynchronisation
     */
    public function __construct(
        string $reason,
        public readonly int $consumed,
        public readonly bool $eof = false,
        public readonly bool $onRs = false,
    ) {
        parent::__construct($reason);
    }

    /**
     * The jq message: reason, optional "at EOF", and "at line L, column C" counted like jq does (the column
     * counter restarts at zero on a newline and counts every consumed byte).
     *
     * @param int $base offset of the first counted byte (a leading BOM is not counted)
     */
    public function describe(string $text, int $base): string
    {
        $consumed = substr($text, $base, $this->consumed - $base);
        $line     = 1 + substr_count($consumed, "\n");
        $newline  = strrpos($consumed, "\n");
        $column   = false === $newline ? \strlen($consumed) : \strlen($consumed) - $newline - 1;

        return $this->getMessage() . ($this->eof ? ' at EOF' : '') . ' at line ' . $line . ', column ' . $column;
    }
}
