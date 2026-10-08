<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use DateTimeImmutable;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Equality, ordering and wildcard matching of nodes, as the comparison, sort and unique operators need.
 */
final class Compare
{
    private const int MAX_DEPTH = 10000;

    /** @var array<string, string> */
    private static array $globs = [];

    private function __construct()
    {
    }

    /**
     * Matches `$subject` against a pattern where `*` stands for any run of characters.
     *
     * @throws EvaluationException when the engine gives up (backtracking limit, malformed UTF-8 subject)
     */
    public static function glob(string $subject, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $subject === $pattern;
        }

        if ('*' === $pattern) {
            return true;
        }

        $regex = self::$globs[$pattern] ?? null;
        if (null === $regex) {
            $parts = array_map(static fn (string $part): string => preg_quote($part, '/'), explode('*', $pattern));
            $regex = '/^' . implode('.*', $parts) . '$/su';
            if (\count(self::$globs) > 512) {
                self::$globs = [];
            }

            self::$globs[$pattern] = $regex;
        }

        return 1 === GoRegex::checked(preg_match($regex, $subject));
    }

    /**
     * The `==` operator: scalars compare by value text (the right side may use `*` wildcards), two nulls
     * are equal, collections compare structurally.
     */
    public static function equals(Node $left, Node $right): bool
    {
        $left  = NodeOps::deref($left);
        $right = NodeOps::deref($right);
        if (NodeKindEnum::Scalar === $left->kind && NodeKindEnum::Scalar === $right->kind) {
            $leftNull  = CoreSchema::TAG_NULL === $left->tag;
            $rightNull = CoreSchema::TAG_NULL === $right->tag;
            if ($leftNull || $rightNull) {
                return $leftNull && $rightNull;
            }

            $leftNumber  = Numbers::of($left);
            $rightNumber = Numbers::of($right);
            if (null !== $leftNumber && null !== $rightNumber) {
                return 0 === ($leftNumber <=> $rightNumber);
            }

            return self::glob($left->value, $right->value);
        }

        return self::deepEquals($left, $right);
    }

    /**
     * Structural equality: mappings ignore key order, scalars compare by value text.
     */
    public static function deepEquals(Node $left, Node $right, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            throw new EvaluationException('Comparison exceeded max depth (alias cycle?)');
        }

        $left  = NodeOps::deref($left);
        $right = NodeOps::deref($right);
        if ($left->kind !== $right->kind) {
            return false;
        }

        switch ($left->kind) {
            case NodeKindEnum::Scalar:
                if (CoreSchema::TAG_NULL === $left->tag || CoreSchema::TAG_NULL === $right->tag) {
                    return $left->tag === $right->tag;
                }

                $leftNumber  = Numbers::of($left);
                $rightNumber = Numbers::of($right);
                if (null !== $leftNumber && null !== $rightNumber) {
                    return 0 === ($leftNumber <=> $rightNumber);
                }

                return $left->value === $right->value;

            case NodeKindEnum::Sequence:
                if (\count($left->content) !== \count($right->content)) {
                    return false;
                }

                // An indexed loop, not array_all(): the recursion must not run inside a native callback.
                for ($position = 0, $count = \count($left->content); $position < $count; ++$position) {
                    if (!self::deepEquals($left->content[$position], $right->content[$position], $depth + 1)) {
                        return false;
                    }
                }

                return true;

            case NodeKindEnum::Mapping:
                if (\count($left->content) !== \count($right->content)) {
                    return false;
                }

                $index = [];
                for ($i = 0, $n = \count($right->content); $i < $n; $i += 2) {
                    $index[$right->content[$i]->value] = $right->content[$i + 1];
                }

                for ($i = 0, $n = \count($left->content); $i < $n; $i += 2) {
                    $other = $index[$left->content[$i]->value] ?? null;
                    if (!$other instanceof Node || !self::deepEquals($left->content[$i + 1], $other, $depth + 1)) {
                        return false;
                    }
                }

                return true;

            default:
                return $left->value === $right->value;
        }
    }

    /**
     * A canonical text for grouping and de-duplication: the text of a scalar, the encoded form of a
     * collection.
     */
    public static function canonical(Node $node, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new EvaluationException('Canonical form exceeded max depth (alias cycle?)');
        }

        $node = NodeOps::deref($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            return $node->value;
        }

        $parts = [];
        if (NodeKindEnum::Sequence === $node->kind) {
            foreach ($node->content as $item) {
                $parts[] = self::canonical($item, $depth + 1);
            }

            return '[' . implode("\x1f", $parts) . ']';
        }

        for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
            $parts[$node->content[$i]->value] = $node->content[$i]->value . "\x1e" . self::canonical($node->content[$i + 1], $depth + 1);
        }

        ksort($parts);

        return '{' . implode("\x1f", $parts) . '}';
    }

    /**
     * Total order used by sort, min, max and the ordering operators: null, booleans (false first), numbers,
     * strings (byte order, or by date when `$layout` is set and both parse), then everything else.
     */
    public static function order(Node $left, Node $right, ?string $layout = null): int
    {
        $left  = NodeOps::deref($left);
        $right = NodeOps::deref($right);
        $rankL = self::rank($left);
        $rankR = self::rank($right);
        if ($rankL !== $rankR) {
            return $rankL <=> $rankR;
        }

        switch ($rankL) {
            case 0:
            default:
                return 0;

            case 1:
                return (int)NodeOps::isTrue($left) <=> (int)NodeOps::isTrue($right);

            case 2:
                $a = Numbers::of($left)  ?? 0;
                $b = Numbers::of($right) ?? 0;
                if (is_nan((float)$a) || is_nan((float)$b)) {
                    return is_nan((float)$a) <=> is_nan((float)$b);
                }

                return $a <=> $b;

            case 3:
                // Hot path (benchmarks yq:group-medium, yq:group-large): equal strings, and strings that do not
                // start with a digit, are never dates, so they skip the two timestamp parses per comparison.
                if ($left->value === $right->value) {
                    return 0;
                }

                if (null === $layout && ('' === $left->value || $left->value[0] > '9' || $left->value[0] < '0' || '' === $right->value || $right->value[0] > '9' || $right->value[0] < '0')) {
                    return strcmp($left->value, $right->value) <=> 0;
                }

                $dates = self::dates($left->value, $right->value, $layout);
                if (null !== $dates) {
                    return $dates;
                }

                return strcmp($left->value, $right->value) <=> 0;
        }
    }

    private static function rank(Node $node): int
    {
        if (NodeKindEnum::Scalar !== $node->kind) {
            return 4;
        }

        return match (NodeOps::effectiveTag($node)) {
            CoreSchema::TAG_NULL                            => 0,
            CoreSchema::TAG_BOOL                            => 1,
            CoreSchema::TAG_INT, CoreSchema::TAG_FLOAT      => 2,
            default                                         => 3,
        };
    }

    private static function dates(string $left, string $right, ?string $layout): ?int
    {
        $a = GoTime::tryParse($left, $layout);
        $b = GoTime::tryParse($right, $layout);
        if (!$a instanceof DateTimeImmutable || !$b instanceof DateTimeImmutable) {
            return null;
        }

        return $a <=> $b;
    }
}
