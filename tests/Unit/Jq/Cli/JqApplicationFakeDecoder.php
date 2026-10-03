<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Generator;
use JsonException;
use LTS\PhpXq\Jq\Cli\ParseDiagnostics;
use LTS\PhpXq\Jq\Cli\ValueScanner;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\JsonSyntaxException;
use stdClass;

/**
 * A small stand-in for the real decoder, built on json_decode, good enough to drive the CLI tests.
 *
 * @internal
 */
final class JqApplicationFakeDecoder implements JsonDecoderInterface
{
    public function decodeOne(string $text): mixed
    {
        $trimmed = trim($text);
        if ('nan' === $trimmed) {
            return \NAN;
        }

        try {
            return self::convert(json_decode($trimmed, false, 512, \JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new JsonSyntaxException('Invalid literal at line 1, column ' . (\strlen($trimmed) + 1));
        }
    }

    public function decodeAll(string $text, bool $seq = false): Generator
    {
        if ($seq) {
            yield from $this->decodeSequence($text);

            return;
        }

        yield from $this->decodeSegment($text, 0, \strlen($text), $text, false);
    }

    /**
     * @return Generator<int, mixed>
     */
    private function decodeSequence(string $text): Generator
    {
        $length = \strlen($text);
        $start  = 0;
        while ($start <= $length) {
            $separator = strpos($text, "\x1e", $start);
            $end       = false === $separator ? $length : $separator;
            yield from $this->decodeSegment($text, $start, $end, $text, false === $separator ? false : true);
            if (false === $separator) {
                return;
            }

            $start = $separator + 1;
        }
    }

    /**
     * @return Generator<int, mixed>
     */
    private function decodeSegment(string $whole, int $start, int $end, string $text, bool $endedBySeparator): Generator
    {
        $scanner = new ValueScanner();
        $slice   = substr($whole, $start, $end - $start);
        $offset  = 0;
        while (true) {
            $status = $scanner->find($slice, $offset);
            if (ValueScanner::NONE === $status) {
                return;
            }

            if (ValueScanner::FOUND === $status) {
                $offset = $scanner->end;

                try {
                    yield $this->decodeOne(substr($slice, $scanner->start, $scanner->end - $scanner->start));
                } catch (JsonSyntaxException) {
                    throw new JsonSyntaxException('Invalid literal at ' . ParseDiagnostics::position($text, $start + $scanner->end + 1));
                }

                continue;
            }

            if ($endedBySeparator) {
                throw new JsonSyntaxException('Truncated value at ' . ParseDiagnostics::position($text, $end + 1));
            }

            throw new JsonSyntaxException('Unfinished JSON term at EOF at ' . ParseDiagnostics::position($text, \strlen($text)));
        }
    }

    private static function convert(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return JsonObject::fromPairs(array_map(self::convert(...), (array)$value));
        }

        if (\is_array($value)) {
            return array_map(self::convert(...), $value);
        }

        return $value;
    }
}
