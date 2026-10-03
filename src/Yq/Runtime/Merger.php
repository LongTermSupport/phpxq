<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;

/**
 * The deep merge behind `*`: mappings merge key by key, sequences are replaced (or appended, or merged
 * by index), scalars are replaced. The flags mirror the reference's modifiers `+` (append arrays),
 * `?` (only keys the left side has), `n` (only keys it lacks), `d` (merge arrays by index) and `c`
 * (clobber custom tags).
 */
final readonly class Merger
{
    public function __construct(
        private bool $appendArrays = false,
        private bool $onlyExisting = false,
        private bool $onlyNew = false,
        private bool $deepArrays = false,
        private bool $clobberTags = false,
    ) {
    }

    public static function fromModifiers(string $modifiers): self
    {
        return new self(
            str_contains($modifiers, '+'),
            str_contains($modifiers, '?'),
            str_contains($modifiers, 'n'),
            str_contains($modifiers, 'd'),
            str_contains($modifiers, 'c'),
        );
    }

    /**
     * Merges `$rhs` into `$lhs` in place; `$lhs` must be a tree the caller owns.
     */
    public function merge(Node $lhs, Node $rhs): void
    {
        $right = NodeOps::deref(NodeOps::unwrap($rhs));
        if (NodeKindEnum::Mapping === $lhs->kind && NodeKindEnum::Mapping === $right->kind) {
            $this->mergeMappings($lhs, $right);
            $this->clobber($lhs, $right);

            return;
        }

        if (NodeKindEnum::Sequence === $lhs->kind && NodeKindEnum::Sequence === $right->kind) {
            $this->mergeSequences($lhs, $right);
            $this->clobber($lhs, $right);

            return;
        }

        NodeOps::updateFrom($lhs, $right, $this->clobberTags);
    }

    private function clobber(Node $lhs, Node $rhs): void
    {
        if ($this->clobberTags) {
            $lhs->tag         = $rhs->tag;
            $lhs->tagExplicit = $rhs->tagExplicit;
        }
    }

    private function mergeMappings(Node $lhs, Node $rhs): void
    {
        $index = [];
        for ($i = 0, $n = \count($lhs->content); $i < $n; $i += 2) {
            $index[$lhs->content[$i]->value] = $i;
        }

        for ($i = 0, $n = \count($rhs->content); $i < $n; $i += 2) {
            $key   = $rhs->content[$i];
            $value = $rhs->content[$i + 1];
            if (isset($index[$key->value])) {
                if ($this->onlyNew) {
                    continue;
                }

                $this->merge($lhs->content[$index[$key->value] + 1], $value);

                continue;
            }

            if ($this->onlyExisting) {
                continue;
            }

            $index[$key->value] = \count($lhs->content);
            $lhs->content[]     = $key->deepCopy();
            $lhs->content[]     = $value->deepCopy();
        }
    }

    private function mergeSequences(Node $lhs, Node $rhs): void
    {
        if ($this->appendArrays) {
            foreach ($rhs->content as $item) {
                $lhs->content[] = $item->deepCopy();
            }

            return;
        }

        if ($this->deepArrays) {
            foreach ($rhs->content as $i => $item) {
                if (isset($lhs->content[$i])) {
                    $this->merge($lhs->content[$i], $item);
                } else {
                    $lhs->content[] = $item->deepCopy();
                }
            }

            return;
        }

        $lhs->content = array_map(static fn (Node $item): Node => $item->deepCopy(), $rhs->content);
    }
}
