<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoderInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;

/**
 * A small stand-in for the real encoder: layout options (indent, tab, sort keys), no colours. It
 * remembers the options of the last call so tests can check what the CLI asked for.
 *
 * @internal
 */
final class JqApplicationFakeEncoder implements JsonEncoderInterface
{
    public ?EncodeOptions $lastOptions = null;

    public function encode(mixed $value, EncodeOptions $options): string
    {
        $this->lastOptions = $options;

        return $this->node($value, $options, 0);
    }

    private function node(mixed $value, EncodeOptions $options, int $level): string
    {
        if (\is_array($value)) {
            return $this->container('[', ']', array_map(fn (mixed $item): string => $this->node($item, $options, $level + 1), $value), $options, $level);
        }

        if ($value instanceof JsonObject) {
            $keys = $options->sortKeys ? $value->sortedKeys() : $value->keys();
            $items = [];
            foreach ($keys as $key) {
                $items[] = json_encode($key, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . ($options->indent > 0 || $options->useTab ? ': ' : ':') . $this->node($value->get($key), $options, $level + 1);
            }

            return $this->container('{', '}', $items, $options, $level);
        }

        return $this->scalar($value);
    }

    /**
     * @param list<string> $items
     */
    private function container(string $open, string $close, array $items, EncodeOptions $options, int $level): string
    {
        if ([] === $items) {
            return $open . $close;
        }

        if (!$options->useTab && 0 === $options->indent) {
            return $open . implode(',', $items) . $close;
        }

        $unit = $options->useTab ? "\t" : str_repeat(' ', $options->indent);
        $pad  = str_repeat($unit, $level + 1);

        return $open . "\n" . $pad . implode(",\n" . $pad, $items) . "\n" . str_repeat($unit, $level) . $close;
    }

    private function scalar(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof PreciseNumber) {
            return $value->literal;
        }

        if (\is_float($value) && is_nan($value)) {
            return 'null';
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);

        return false === $encoded ? 'null' : $encoded;
    }
}
