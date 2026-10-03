<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * A compiled jq regex: an Oniguruma pattern plus jq's modifier string, translated to PCRE once and cached.
 *
 * Modifiers: `g` global, `i` case-insensitive, `x` extended, `s` single-line (PCRE's default, so a no-op),
 * `p` dot matches newline (and single-line), `n` ignore empty matches, `l` longest match (accepted, but PCRE
 * cannot do leftmost-longest, so it has no effect). `n` rejects empty matches after the fact, so it does not
 * backtrack into a non-empty alternative at the same position the way Oniguruma's option does.
 *
 * @internal
 */
final class OnigRegex
{
    private const int CACHE_LIMIT = 512;

    /**
     * Oniguruma's message for the PCRE compile errors that jq users hit most.
     */
    private const array MESSAGES = [
        'quantifier does not follow a repeatable item'  => 'target of repeat operator is not specified',
        'missing closing parenthesis'                   => 'end pattern with unmatched parenthesis',
        'unmatched closing parenthesis'                 => 'unmatched close parenthesis',
        'missing terminating ] for character class'     => 'premature end of char-class',
        '\ at end of pattern'                           => 'end pattern at escape',
        'range out of order in character class'         => 'empty range in char class',
        'reference to non-existent subpattern'          => 'undefined name reference',
        'unknown property name after \P or \p'          => 'invalid character property name',
        'unrecognized character follows \\'             => 'invalid backref number/name',
    ];

    /** @var array<string, self> */
    private static array $cache = [];

    private ?string $unicodeWordPcre = null;

    /**
     * @param list<?string> $groupNames
     */
    private function __construct(
        public readonly string $source,
        public readonly bool $global,
        public readonly bool $ignoreEmpty,
        public readonly array $groupNames,
        private readonly string $modifiers,
        private readonly bool $extended,
        private readonly string $nativePcre,
        private readonly bool $usesWordEscapes,
    ) {
    }

    /**
     * @throws JqException for an unknown modifier or a pattern that does not compile
     */
    public static function compile(string $source, ?string $flags): self
    {
        $key = $source . "\0" . $flags;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $global      = false;
        $ignoreEmpty = false;
        $extended    = false;
        $modifiers   = 'u';
        foreach (str_split($flags ?? '') as $flag) {
            switch ($flag) {
                case 'g':
                    $global = true;

                    break;

                case 'i':
                    $modifiers .= 'i';

                    break;

                case 'x':
                    $modifiers .= 'x';
                    $extended   = true;

                    break;

                case 'n':
                    $ignoreEmpty = true;

                    break;

                case 'p':
                    $modifiers .= 's';

                    break;

                case 's':
                case 'l':
                    break;

                default:
                    throw new JqException($flags . ' is not a valid modifier string');
            }
        }

        $translated = RegexTranslator::translate($source, $extended, false);
        $regex      = new self(
            $source,
            $global,
            $ignoreEmpty,
            $translated->groupNames,
            $modifiers,
            $extended,
            '/' . $translated->pcre . '/' . $modifiers,
            $translated->usesWordEscapes,
        );
        $regex->verify($regex->nativePcre);

        if (\count(self::$cache) >= self::CACHE_LIMIT) {
            self::$cache = [];
        }

        return self::$cache[$key] = $regex;
    }

    /**
     * The delimited PCRE pattern to run against a subject; $ascii says the subject has no non-ASCII bytes.
     */
    public function pcre(bool $ascii): string
    {
        if ($ascii || !$this->usesWordEscapes) {
            return $this->nativePcre;
        }

        if (null === $this->unicodeWordPcre) {
            $translated            = RegexTranslator::translate($this->source, $this->extended, true);
            $this->unicodeWordPcre = '/' . $translated->pcre . '/' . $this->modifiers;
            $this->verify($this->unicodeWordPcre);
        }

        return $this->unicodeWordPcre;
    }

    private function verify(string $delimited): void
    {
        $message = null;
        set_error_handler(static function (int $level, string $text) use (&$message): bool {
            $message = $text;

            return true;
        });

        try {
            $result = preg_match($delimited, '');
        } finally {
            restore_error_handler();
        }

        if (false === $result) {
            throw RegexTranslator::invalid($this->source, self::describe($message));
        }
    }

    private static function describe(?string $message): string
    {
        if (null === $message || 1 !== preg_match('/Compilation failed: (.*) at offset \d+/', $message, $parts)) {
            return $message ?? 'invalid pattern';
        }

        return self::MESSAGES[$parts[1]] ?? $parts[1];
    }
}
