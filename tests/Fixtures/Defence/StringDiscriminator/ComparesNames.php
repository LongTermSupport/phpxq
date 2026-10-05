<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\StringDiscriminator;

/**
 * Each method tells one subject apart by two or more different word-like literals.
 */
final class ComparesNames
{
    public string $mode = 'plain';

    public static string $format = 'json';

    public ?self $child = null;

    public function identical(string $kind): int
    {
        if ('head' === $kind) {
            return 1;
        }

        return 'line' !== $kind ? 2 : 3;
    }

    public function looseAndReversed(string $kind): bool
    {
        return 'yes' === $kind || 'no' === $kind || 'maybe' !== $kind;
    }

    public function inArrayAndComparison(string $kind): bool
    {
        return \in_array($kind, ['head', 'foot'], true) || 'line' === $kind;
    }

    public function matchArms(string $kind): int
    {
        return match ($kind) {
            'head', 'foot' => 1,
            'line'         => 2,
            default        => 0,
        };
    }

    public function switchCases(string $kind): int
    {
        switch ($kind) {
            case 'head':
                return 1;

            case 'foot':
                return 2;

            default:
                return 0;
        }
    }

    public function propertyAndStaticProperty(): bool
    {
        return 'plain' === $this->mode || 'rich' === $this->mode || 'json' === self::$format || 'yaml' === self::$format;
    }

    public function nullsafeProperty(): bool
    {
        return 'a' === $this->child?->mode || 'b' === $this->child?->mode;
    }

    public function oneFindingPerSubjectAndMethod(string $kind, string $other): bool
    {
        return 'head' === $kind && 'foot' === $kind && 'line' === $kind && 'x1' === $other && 'x2' === $other;
    }

    public function manyLiterals(string $kind): bool
    {
        return \in_array($kind, ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'], true);
    }

    public function anonymousClassIsAScopeOfItsOwn(string $kind): object
    {
        $same = 'head' === $kind;

        return new class {
            public function inside(string $kind): bool
            {
                return 'a' === $kind || 'b' === $kind;
            }
        };
    }

    public function namedArgumentsToInArray(string $kind): bool
    {
        return \in_array(haystack: ['head', 'foot'], needle: $kind, strict: true);
    }
}
