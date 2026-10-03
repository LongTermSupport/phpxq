<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

/**
 * Which YAML scalar styles can represent a string, decided by the same byte-level analysis as the
 * reference emitter (libyaml's `yaml_emitter_analyze_scalar`, as ported by go-yaml v3, with unicode
 * output enabled).
 *
 * @internal
 */
final readonly class ScalarAnalysis
{
    private const string SIMPLE = '/\A[A-Za-z0-9_\/][A-Za-z0-9_.\/ -]*+(?<! )\z/';

    private function __construct(
        public bool $multiline,
        public bool $flowPlainAllowed,
        public bool $blockPlainAllowed,
        public bool $singleQuotedAllowed,
        public bool $blockAllowed,
    ) {
    }

    /**
     * @param string $value valid UTF-8
     */
    public static function of(string $value): self
    {
        if ('' === $value) {
            return new self(false, false, true, true, false);
        }

        if (1 === preg_match(self::SIMPLE, $value)) {
            return new self(false, true, true, true, true);
        }

        $length          = \strlen($value);
        $blockIndicators = str_starts_with($value, '---') || str_starts_with($value, '...');
        $flowIndicators  = $blockIndicators;
        $lineBreaks      = false;
        $special         = false;
        $leadingSpace    = false;
        $leadingBreak    = false;
        $trailingSpace   = false;
        $trailingBreak   = false;
        $breakSpace      = false;
        $spaceBreak      = false;
        $precededByBlank = true;
        $previousSpace   = false;
        $previousBreak   = false;
        $width           = 1;

        for ($i = 0; $i < $length; $i += $width) {
            $byte            = \ord($value[$i]);
            $width           = self::width($byte);
            $next            = $i + $width;
            $followedByBlank = $next >= $length || ' ' === $value[$next] || "\t" === $value[$next];

            if (0 === $i) {
                switch ($value[0]) {
                    case '#':
                    case ',':
                    case '[':
                    case ']':
                    case '{':
                    case '}':
                    case '&':
                    case '*':
                    case '!':
                    case '|':
                    case '>':
                    case "'":
                    case '"':
                    case '%':
                    case '@':
                    case '`':
                        $flowIndicators  = true;
                        $blockIndicators = true;

                        break;

                    case '?':
                    case ':':
                        $flowIndicators = true;
                        if ($followedByBlank) {
                            $blockIndicators = true;
                        }

                        break;

                    case '-':
                        if ($followedByBlank) {
                            $flowIndicators  = true;
                            $blockIndicators = true;
                        }

                        break;
                }
            } else {
                switch ($value[$i]) {
                    case ',':
                    case '?':
                    case '[':
                    case ']':
                    case '{':
                    case '}':
                        $flowIndicators = true;

                        break;

                    case ':':
                        $flowIndicators = true;
                        if ($followedByBlank) {
                            $blockIndicators = true;
                        }

                        break;

                    case '#':
                        if ($precededByBlank) {
                            $flowIndicators  = true;
                            $blockIndicators = true;
                        }

                        break;
                }
            }

            if (!self::isPrintable($value, $i, $byte)) {
                $special = true;
            }

            $isBreak = self::isBreak($value, $i, $byte);
            if ($isBreak) {
                $lineBreaks = true;
            }

            if (0x20 === $byte) {
                if (0 === $i) {
                    $leadingSpace = true;
                }

                if ($next === $length) {
                    $trailingSpace = true;
                }

                if ($previousBreak) {
                    $breakSpace = true;
                }

                $previousSpace = true;
                $previousBreak = false;
            } elseif ($isBreak) {
                if (0 === $i) {
                    $leadingBreak = true;
                }

                if ($next === $length) {
                    $trailingBreak = true;
                }

                if ($previousSpace) {
                    $spaceBreak = true;
                }

                $previousSpace = false;
                $previousBreak = true;
            } else {
                $previousSpace = false;
                $previousBreak = false;
            }

            $precededByBlank = 0x20 === $byte || 0x09 === $byte || $isBreak;
        }

        $flowPlain  = true;
        $blockPlain = true;
        $single     = true;
        $blockStyle = true;

        if ($leadingSpace || $leadingBreak || $trailingSpace || $trailingBreak) {
            $flowPlain  = false;
            $blockPlain = false;
        }

        if ($trailingSpace) {
            $blockStyle = false;
        }

        if ($breakSpace) {
            $flowPlain  = false;
            $blockPlain = false;
            $single     = false;
        }

        if ($spaceBreak || $special) {
            $flowPlain  = false;
            $blockPlain = false;
            $single     = false;
            $blockStyle = false;
        }

        if ($lineBreaks) {
            $flowPlain  = false;
            $blockPlain = false;
        }

        if ($flowIndicators) {
            $flowPlain = false;
        }

        if ($blockIndicators) {
            $blockPlain = false;
        }

        return new self($lineBreaks, $flowPlain, $blockPlain, $single, $blockStyle);
    }

    private static function width(int $leadByte): int
    {
        return match (true) {
            $leadByte < 0x80 => 1,
            $leadByte < 0xE0 => 2,
            $leadByte < 0xF0 => 3,
            default          => 4,
        };
    }

    private static function isPrintable(string $value, int $i, int $byte): bool
    {
        if ($byte < 0x80) {
            return 0x0A === $byte || ($byte >= 0x20 && $byte <= 0x7E);
        }

        $second = \ord($value[$i + 1]);

        return match (true) {
            0xC2 === $byte                                 => $second >= 0xA0,
            $byte > 0xC2 && $byte                   < 0xED => true,
            0xED === $byte                                 => $second < 0xA0,
            0xEE === $byte                                 => true,
            0xEF === $byte                                 => (0xBB !== $second || 0xBF !== \ord($value[$i + 2]))
                && (0xBF !== $second || !\in_array(\ord($value[$i + 2]), [0xBE, 0xBF], true)),
            default                                        => false,
        };
    }

    private static function isBreak(string $value, int $i, int $byte): bool
    {
        if ($byte < 0x80) {
            return 0x0A === $byte || 0x0D === $byte;
        }

        if (0xC2 === $byte) {
            return 0x85 === \ord($value[$i + 1]);
        }

        return 0xE2 === $byte
            && 0x80 === \ord($value[$i + 1])
            && \in_array(\ord($value[$i + 2]), [0xA8, 0xA9], true);
    }
}
