<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\Values;

/**
 * jq's error message vocabulary: values shown in messages are dumped as compact JSON and truncated.
 *
 * @internal
 */
final class ErrorText
{
    private const int DUMP_LIMIT = 29;

    private static ?JsonEncoder $encoder = null;

    private function __construct()
    {
    }

    public static function json(mixed $value): string
    {
        self::$encoder ??= new JsonEncoder();

        return self::$encoder->encode($value, EncodeOptions::compact());
    }

    /**
     * Compact JSON of at most 29 bytes: a longer dump is cut on a codepoint boundary and marked with `...`
     * (a string keeps its closing quote after the marker).
     */
    public static function dump(mixed $value): string
    {
        $json = self::json($value);
        if (\strlen($json) <= self::DUMP_LIMIT) {
            return $json;
        }

        $isString = \is_string($value);
        $cut      = $isString ? 25 : 26;
        while ($cut > 0 && (\ord($json[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }

        return substr($json, 0, $cut) . ($isString ? '..."' : '...');
    }

    public static function typeError(mixed $value, string $message): JqException
    {
        return new JqException(\sprintf('%s (%s) %s', Values::typeName($value), self::dump($value), $message));
    }

    public static function typeError2(mixed $left, mixed $right, string $message): JqException
    {
        return new JqException(\sprintf(
            '%s (%s) and %s (%s) %s',
            Values::typeName($left),
            self::dump($left),
            Values::typeName($right),
            self::dump($right),
            $message,
        ));
    }

    public static function iterateError(mixed $target): JqException
    {
        return new JqException(\sprintf('Cannot iterate over %s (%s)', Values::typeName($target), self::dump($target)));
    }

    public static function indexError(mixed $target, mixed $key): JqException
    {
        return new JqException(\sprintf('Cannot index %s with %s (%s)', Values::typeName($target), Values::typeName($key), self::dump($key)));
    }
}
