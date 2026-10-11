<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\FormatException;

/**
 * Pure string transforms behind the `@base64`, `@base64d`, `@base64url`, `@base64urld`, `@uri`, `@urid`
 * and `@sh` expression encoders and the base64, base64url, uri and shell codecs. No trailing newline is
 * added or removed; the expression operators and the codecs decide that.
 *
 * @internal
 */
final readonly class StringFormats
{
    private const string SHELL_SAFE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_@%+=:,./-';

    private function __construct()
    {
    }

    public static function base64Encode(string $text): string
    {
        return base64_encode($text);
    }

    /**
     * Whitespace is ignored and missing padding is tolerated.
     *
     * @throws FormatException
     */
    public static function base64Decode(string $text): string
    {
        return self::decodeBase64($text, false);
    }

    public static function base64UrlEncode(string $text): string
    {
        return strtr(base64_encode($text), '+/', '-_');
    }

    /**
     * @throws FormatException
     */
    public static function base64UrlDecode(string $text): string
    {
        return self::decodeBase64($text, true);
    }

    /**
     * Go's url.QueryEscape: unreserved characters (letters, digits, `-_.~`) stay, a space becomes `+`,
     * everything else is percent-encoded.
     */
    public static function uriEncode(string $text): string
    {
        return str_replace('%20', '+', rawurlencode($text));
    }

    /**
     * Go's url.QueryUnescape.
     *
     * @throws FormatException
     */
    public static function uriDecode(string $text): string
    {
        if (1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $text)) {
            throw new FormatException('invalid URL escape in ' . $text);
        }

        return urldecode($text);
    }

    /**
     * One shell word for a value: bare when every character is safe, otherwise single-quoted with an
     * embedded single quote written as `'"'"'`. Used for shell variable output.
     */
    public static function shellQuote(string $text): string
    {
        if ('' === $text) {
            return "''";
        }

        if (\strlen($text) === strspn($text, self::SHELL_SAFE)) {
            return $text;
        }

        return "'" . str_replace("'", "'\"'\"'", $text) . "'";
    }

    private static function decodeBase64(string $text, bool $urlSafe): string
    {
        $clean = preg_replace('/\s+/', '', $text);
        $clean ??= '';

        if ($urlSafe) {
            $clean = strtr($clean, '-_', '+/');
        }

        $remainder = \strlen($clean) % 4;
        if (0 !== $remainder) {
            $clean .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($clean, true);
        if (false === $decoded) {
            throw new FormatException('illegal base64 data');
        }

        return $decoded;
    }
}
