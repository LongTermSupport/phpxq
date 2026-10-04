<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\JsonSyntaxException;
use LTS\PhpXq\Json\NumberParser;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * Type tests and conversions: type, length, keys, has, contains, tojson/fromjson, tonumber, tostring and the
 * float classification builtins.
 *
 * @internal
 */
final class TypeFunctions
{
    private const int MAX_DEPTH = 10000;

    /** The strings `toboolean` reads, with the boolean each one stands for. */
    private const array BOOLEAN_TEXTS = [
        'true'  => true,
        'false' => false,
    ];

    private const string NUMBER_TEXT = '/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D';

    private static ?JsonDecoder $decoder = null;

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        self::add($registry, 'type', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Values::typeName($v));
        self::add($registry, 'not', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => !Values::isTruthy($v));
        self::add($registry, 'length', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::length($v));
        self::add($registry, 'utf8bytelength', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_string($v)
            ? \strlen($v)
            : throw Problems::type($v, 'only strings have UTF-8 byte length'));
        self::add($registry, 'keys', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::keys($v, true));
        self::add($registry, 'keys_unsorted', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::keys($v, false));
        self::add($registry, 'has', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::has($v, $a[0]));
        self::add($registry, 'contains', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::containsChecked($v, $a[0]));
        self::add($registry, 'tojson', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Problems::json($v));
        self::add($registry, 'tostring', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_string($v) ? $v : Problems::json($v));
        self::add($registry, 'fromjson', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::fromJson($v));
        self::add($registry, 'tonumber', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::toNumber($v));
        self::add($registry, 'toboolean', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::toBoolean($v));
        self::add($registry, 'toarray', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_array($v) ? $v : [$v]);
        self::add($registry, 'abs', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::abs($v));
        self::add($registry, 'ascii', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::ascii($v));
        self::add($registry, 'infinite', 0, static fn (): mixed => \INF);
        self::add($registry, 'nan', 0, static fn (): mixed => \NAN);
        self::add($registry, 'isinfinite', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => is_infinite(self::number($v)));
        self::add($registry, 'isnan', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => is_nan(self::number($v)));
        self::add($registry, 'isnormal', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::isNormal(self::number($v)));
        self::add($registry, 'have_literal_numbers', 0, static fn (): mixed => true);
        self::add($registry, 'have_decnum', 0, static fn (): mixed => true);
    }

    /**
     * jq's `length`.
     */
    public static function length(mixed $value): int|float|PreciseNumber
    {
        return match (true) {
            \is_string($value)                => Unicode::length($value),
            \is_array($value)                 => \count($value),
            $value instanceof JsonObject      => \count($value),
            null === $value                   => 0,
            \is_int($value)                   => abs($value),
            \is_float($value)                 => abs($value),
            $value instanceof PreciseNumber   => NumberParser::abs($value),
            default                           => throw Problems::type($value, 'has no length'),
        };
    }

    /**
     * @return list<int|string>
     */
    public static function keys(mixed $value, bool $sorted): array
    {
        if ($value instanceof JsonObject) {
            return $sorted ? $value->sortedKeys() : $value->keys();
        }

        if (\is_array($value)) {
            return array_keys($value);
        }

        throw Problems::type($value, 'has no keys');
    }

    public static function has(mixed $value, mixed $key): bool
    {
        if ($value instanceof JsonObject && \is_string($key)) {
            return $value->has($key);
        }

        if (\is_array($value) && Num::isNumber($key)) {
            $index = Num::toFloat($key);

            return $index >= 0 && $index < \count($value);
        }

        throw new JqException(\sprintf('Cannot check whether %s has a %s key', Values::typeName($value), Values::typeName($key)));
    }

    /**
     * jq's `contains`: values of different kinds are an error, then containment is structural.
     */
    public static function containsChecked(mixed $haystack, mixed $needle): bool
    {
        if (self::kind($haystack) !== self::kind($needle)) {
            throw Problems::type2($haystack, $needle, 'cannot have their containment checked');
        }

        return self::contains($haystack, $needle, 0);
    }

    public static function toNumber(mixed $value): mixed
    {
        if (Num::isNumber($value)) {
            return $value;
        }

        if (\is_string($value) && (1 === preg_match(self::NUMBER_TEXT, $value) || 1 === preg_match('/^[+-]?(?:nan|inf|infinity)$/Di', $value))) {
            $number = NumberParser::tryParse($value);
            if (null !== $number) {
                return $number;
            }
        }

        throw Problems::type($value, 'cannot be parsed as a number');
    }

    private static function toBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (!\is_string($value) || !isset(self::BOOLEAN_TEXTS[$value])) {
            throw Problems::type($value, 'cannot be parsed as a boolean');
        }

        return self::BOOLEAN_TEXTS[$value];
    }

    private static function fromJson(mixed $value): mixed
    {
        if (!\is_string($value)) {
            throw Problems::type($value, 'only strings can be parsed');
        }

        self::$decoder ??= new JsonDecoder();

        try {
            return self::$decoder->decodeOne($value);
        } catch (JsonSyntaxException $jsonSyntaxException) {
            throw new JqException($jsonSyntaxException->getMessage(), $jsonSyntaxException);
        }
    }

    private static function abs(mixed $value): mixed
    {
        if (\is_int($value)) {
            return abs($value);
        }

        if (\is_float($value)) {
            return Num::of(abs($value));
        }

        if ($value instanceof PreciseNumber) {
            return NumberParser::abs($value);
        }

        return $value;
    }

    private static function ascii(mixed $value): string
    {
        if (Num::isNumber($value)) {
            $code = Num::toFloat($value);
            if ($code >= 0 && $code <= 127) {
                return Unicode::encode((int)$code);
            }
        }

        throw new JqException('ascii only takes numbers between 0 and 127');
    }

    private static function number(mixed $value): float
    {
        if (!Num::isNumber($value)) {
            throw Problems::type($value, 'number required');
        }

        return Num::toFloat($value);
    }

    private static function isNormal(float $value): bool
    {
        return is_finite($value) && abs($value) >= \PHP_FLOAT_MIN;
    }

    private static function kind(mixed $value): string
    {
        if (true === $value) {
            return 'true';
        }

        return false === $value ? 'false' : Values::typeName($value);
    }

    private static function contains(mixed $haystack, mixed $needle, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            throw new JqException('Containment check too deep');
        }

        if ($haystack instanceof JsonObject && $needle instanceof JsonObject) {
            foreach ($needle->entries() as $key => $wanted) {
                if (!$haystack->has($key) || !self::contains($haystack->get($key), $wanted, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (\is_array($haystack) && array_is_list($haystack) && \is_array($needle) && array_is_list($needle)) {
            $wanted = \count($needle);
            for ($i = 0; $i < $wanted; ++$i) {
                if (!self::anyContains($haystack, $needle[$i], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (\is_string($haystack) && \is_string($needle)) {
            return str_contains($haystack, $needle);
        }

        return Values::equals($haystack, $needle);
    }

    /**
     * Whether some element of the array contains $wanted. The loops of this class are indexed rather than
     * `array_any`/`array_all` with a closure: a closure callback re-enters the engine through native
     * code, so a depth of 10000 would exhaust the C stack, while plain PHP calls do not.
     *
     * @param list<mixed> $haystack
     */
    private static function anyContains(array $haystack, mixed $wanted, int $depth): bool
    {
        $wantedKind = self::kind($wanted);
        $count      = \count($haystack);
        for ($i = 0; $i < $count; ++$i) {
            $candidate = $haystack[$i];
            if (self::kind($candidate) === $wantedKind && self::contains($candidate, $wanted, $depth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Closure(RuntimeContextInterface, mixed, list<mixed>): mixed $function
     */
    private static function add(BuiltinRegistryInterface $registry, string $name, int $arity, Closure $function): void
    {
        $registry->register(new ValueFunction($name, $arity, $function));
    }
}
