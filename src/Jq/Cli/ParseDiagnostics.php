<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonSyntaxException;

/**
 * jq's parse error messages with positions that refer to the whole input. The CLI decodes slices of
 * the input, so a slice's own "line 1, column 7" would be wrong; when a slice fails, the text from the
 * failure point on is decoded again with everything before it blanked out (newlines kept), which makes
 * the decoder report the position jq would.
 *
 * @api
 */
final readonly class ParseDiagnostics
{
    public function __construct(
        private JsonDecoderInterface $decoder,
    ) {
    }

    /**
     * The decoder's error for the text starting at $offset, as if the whole $text had been parsed.
     */
    public function message(string $text, int $offset, bool $seq = false): string
    {
        $blank = (string)preg_replace('/[^\n]/', ' ', substr($text, 0, $offset));

        try {
            foreach ($this->decoder->decodeAll($blank . substr($text, $offset), $seq) as $ignored) {
                unset($ignored);
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            return $jsonSyntaxException->getMessage();
        }

        return 'Invalid JSON text';
    }

    /**
     * "line L, column C" after $consumed bytes of $text have been read: the line counts from 1, the
     * column counts the bytes since the last newline.
     */
    public static function position(string $text, int $consumed): string
    {
        $prefix  = substr($text, 0, $consumed);
        $newline = strrpos($prefix, "\n");

        return \sprintf(
            'line %d, column %d',
            1 + substr_count($prefix, "\n"),
            false === $newline ? $consumed : $consumed - $newline - 1,
        );
    }
}
