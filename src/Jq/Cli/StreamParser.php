<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use Generator;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * `--stream`: parses JSON text into jq's streaming events without building the whole value, and
 * reports structural errors with jq's wording.
 *
 * Events are `[path, leaf]` for scalars and empty containers, and `[path]` closing a non-empty
 * container (the path of its last child). Everything a token decodes to goes through the injected
 * decoder, so number and string semantics are those of the normal input path. The generator key is
 * the offset in the text just after the item, which the caller turns into a line number.
 *
 * @api
 */
final readonly class StreamParser
{
    // Where the parser is inside the innermost container.
    private const int ARRAY_FIRST  = 0;

    // after `[`: a value or `]`
    private const int ARRAY_NEXT   = 1;

    // after `,`: a value
    private const int ARRAY_AFTER  = 2;

    // after a value: `,` or `]`
    private const int OBJECT_FIRST = 3;

    // after `{`: a key or `}`
    private const int OBJECT_NEXT  = 4;

    // after `,`: a key
    private const int OBJECT_COLON = 5;

    // after a key: `:`
    private const int OBJECT_VALUE = 6;

    // after `:`: a value
    private const int OBJECT_AFTER = 7; // after a value: `,` or `}`

    public function __construct(
        private JsonDecoderInterface $decoder,
        private ParseDiagnostics $diagnostics,
    ) {
    }

    /**
     * @param bool $seq RS (0x1E) abandons the value in progress and the parser carries on after an error
     *
     * @return Generator<int, list<mixed>|StreamError>
     */
    public function events(string $text, bool $seq): Generator
    {
        $length = \strlen($text);
        $index  = 0;
        /** @var array<int, int|string|null> $path */
        $path = [];
        /** @var array<int, bool> $isArray */
        $isArray = [];
        /** @var array<int, int> $modes */
        $modes = [];

        while (true) {
            $index += strspn($text, " \t\r\n", $index);
            if ($index >= $length) {
                break;
            }

            $depth = \count($modes);
            $char  = $text[$index];
            $error = null;

            if ($seq && "\x1e" === $char) {
                if ($depth > 0) {
                    yield $index + 1 => new StreamError(
                        'Truncated value at ' . ParseDiagnostics::position($text, $index + 1),
                        $path,
                        false,
                    );
                    $path    = [];
                    $isArray = [];
                    $modes   = [];
                }

                ++$index;

                continue;
            }

            switch ($char) {
                case '[':
                case '{':
                    $error = $depth > 0 ? $this->valueRefusal($modes[$depth - 1], $char) : null;
                    if (null === $error) {
                        $isArray[] = '[' === $char;
                        $modes[]   = '[' === $char ? self::ARRAY_FIRST : self::OBJECT_FIRST;
                        $path[]    = '[' === $char ? 0 : null;
                        ++$index;
                    }

                    break;

                case ']':
                case '}':
                    $error = $this->close($char, $depth, $isArray, $modes, $path, $leaf);
                    if (null === $error) {
                        if (null !== $leaf) {
                            yield $index + 1 => [$path, $leaf[0]];
                        } else {
                            yield $index + 1 => [$path];
                            array_pop($path);
                            array_pop($isArray);
                            array_pop($modes);
                            $this->markValueDone($modes, $isArray);
                        }

                        ++$index;
                    }

                    break;

                case ',':
                    $error = $this->comma($depth > 0 ? $modes[$depth - 1] : -1);
                    if (null === $error) {
                        $top = $depth - 1;
                        if ($isArray[$top]) {
                            $modes[$top] = self::ARRAY_NEXT;
                            $path[$top]  = (int)$path[$top] + 1;
                        } else {
                            $modes[$top] = self::OBJECT_NEXT;
                            $path[$top]  = null;
                        }

                        ++$index;
                    }

                    break;

                case ':':
                    $error = $this->colon($depth > 0 ? $modes[$depth - 1] : -1);
                    if (null === $error) {
                        $modes[$depth - 1] = self::OBJECT_VALUE;
                        ++$index;
                    }

                    break;

                default:
                    $start = $index;
                    if ('"' === $char) {
                        $end = $this->stringEnd($text, $index + 1, $length);
                        if ($end < 0) {
                            yield $length => new StreamError(
                                'Unfinished string at EOF at ' . ParseDiagnostics::position($text, $length),
                                $path,
                                !$seq,
                            );
                            $end = $length;
                            if (!$seq) {
                                return;
                            }

                            $path    = [];
                            $isArray = [];
                            $modes   = [];
                            $index   = $length;

                            break;
                        }
                    } else {
                        $end = $index + strcspn($text, " \t\r\n[]{},:\"" . ($seq ? "\x1e" : ''), $index);
                    }

                    $decoded = false;
                    $value   = $this->decoder->tryDecodeOne(substr($text, $start, $end - $start), $decoded);
                    if (!$decoded) {
                        $message = $this->diagnostics->message($text, $start, $seq);
                        yield $end => new StreamError($message, $path, !$seq);
                        if (!$seq) {
                            return;
                        }

                        $path    = [];
                        $isArray = [];
                        $modes   = [];
                        $nextRs  = strpos($text, "\x1e", $start);
                        $index   = false === $nextRs ? $length : $nextRs;

                        break;
                    }

                    $top = $depth - 1;
                    if ($depth > 0 && !$isArray[$top] && (self::OBJECT_FIRST === $modes[$top] || self::OBJECT_NEXT === $modes[$top])) {
                        if (\is_string($value) && '"' === $char) {
                            $path[$top]  = $value;
                            $modes[$top] = self::OBJECT_COLON;
                            $index       = $end;

                            break;
                        }

                        $error = 'Object keys must be strings';
                        $index = $end;

                        break;
                    }

                    $error = $depth > 0 ? $this->valueRefusal($modes[$top], '') : null;
                    $index = $end;
                    if (null === $error) {
                        yield $end => [$path, $value];
                        $this->markValueDone($modes, $isArray);
                    }

                    break;
            }

            if (null !== $error) {
                $consumed = min($length, $index + 1);

                yield $consumed => new StreamError(
                    $error . ' at ' . ParseDiagnostics::position($text, $consumed),
                    $path,
                    !$seq,
                );
                if (!$seq) {
                    return;
                }

                $path    = [];
                $isArray = [];
                $modes   = [];
                $nextRs  = strpos($text, "\x1e", $index);
                $index   = false === $nextRs ? $length : $nextRs;
            }
        }

        if ([] !== $modes) {
            yield $length => new StreamError(
                ($seq ? 'Unfinished abandoned text' : 'Unfinished JSON term') . ' at EOF at ' . ParseDiagnostics::position($text, $length),
                $path,
                !$seq,
            );
        }
    }

    /**
     * Why a value that starts with $what (`[`, `{` or empty for a scalar) cannot appear in $mode, or null.
     */
    private function valueRefusal(int $mode, string $what): ?string
    {
        return match ($mode) {
            self::ARRAY_FIRST, self::ARRAY_NEXT, self::OBJECT_VALUE => null,
            self::OBJECT_FIRST                                      => '' === $what ? null : "Expected string key after '{', not '" . $what . "'",
            self::OBJECT_NEXT                                       => '' === $what ? null : "Expected string key after ',' in object, not '" . $what . "'",
            default                                                 => 'Expected separator between values',
        };
    }

    private function comma(int $mode): ?string
    {
        return match ($mode) {
            -1                                                                         => "',' not as part of an object or array",
            self::ARRAY_AFTER, self::OBJECT_AFTER                                      => null,
            self::OBJECT_COLON                                                         => 'Objects must consist of key:value pairs',
            default                                                                    => "Expected value before ','",
        };
    }

    private function colon(int $mode): ?string
    {
        return match ($mode) {
            self::OBJECT_COLON                    => null,
            self::OBJECT_FIRST, self::OBJECT_NEXT => "Expected string key before ':'",
            self::OBJECT_VALUE                    => "':' should follow a key",
            default                               => "':' not as part of an object",
        };
    }

    /**
     * Validates `]` / `}` against the state. Sets $leaf to `[emptyValue]` when the container was empty
     * (and pops it), leaves it null when the container had members.
     *
     * @param array<int, bool>            $isArray
     * @param array<int, int>             $modes
     * @param array<int, int|string|null> $path
     * @param ?array{mixed}               $leaf
     */
    private function close(string $char, int $depth, array &$isArray, array &$modes, array &$path, ?array &$leaf): ?string
    {
        $leaf = null;
        if (0 === $depth) {
            return "Unmatched '" . $char . "' at the top-level";
        }

        $top = $depth - 1;
        if (']' === $char) {
            if (!$isArray[$top]) {
                return "Unmatched ']' in the middle of an object";
            }

            if (self::ARRAY_NEXT === $modes[$top]) {
                return 'Expected another array element';
            }

            $empty = [];
        } else {
            if ($isArray[$top]) {
                return "Unmatched '}' in the middle of an array";
            }

            if (self::OBJECT_NEXT === $modes[$top]) {
                return 'Expected another key-value pair';
            }

            if (self::OBJECT_COLON === $modes[$top] || self::OBJECT_VALUE === $modes[$top]) {
                return 'Objects must consist of key:value pairs';
            }

            $empty = new JsonObject();
        }

        if (self::ARRAY_FIRST === $modes[$top] || self::OBJECT_FIRST === $modes[$top]) {
            array_pop($path);
            array_pop($isArray);
            array_pop($modes);
            $leaf = [$empty];
            $this->markValueDone($modes, $isArray);
        }

        return null;
    }

    /**
     * A value just ended inside the innermost container: it now expects `,` or the closing bracket.
     *
     * @param array<int, int>  $modes
     * @param array<int, bool> $isArray
     */
    private function markValueDone(array &$modes, array $isArray): void
    {
        $top = \count($modes) - 1;
        if ($top >= 0) {
            $modes[$top] = $isArray[$top] ? self::ARRAY_AFTER : self::OBJECT_AFTER;
        }
    }

    private function stringEnd(string $text, int $index, int $length): int
    {
        while (true) {
            $index += strcspn($text, '"\\', $index);
            if ($index >= $length) {
                return -1;
            }

            if ('"' === $text[$index]) {
                return $index + 1;
            }

            $index += 2;
        }
    }
}
