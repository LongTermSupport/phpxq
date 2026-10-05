<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Numbers;
use LTS\PhpXq\Yq\Runtime\Traversal;

/**
 * Ordering operators: `sort`, `sort_by`, `group_by`, `unique`, `unique_by`, `min`, `max`, `reverse`,
 * `shuffle`. On a mapping `sort` and `sort_by` reorder the entries.
 */
final readonly class SortingCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return BuiltinNameEnum::values(
            BuiltinNameEnum::Sort,
            BuiltinNameEnum::SortBy,
            BuiltinNameEnum::GroupBy,
            BuiltinNameEnum::Unique,
            BuiltinNameEnum::UniqueBy,
            BuiltinNameEnum::Min,
            BuiltinNameEnum::Max,
            BuiltinNameEnum::Reverse,
            BuiltinNameEnum::Shuffle,
        );
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $out = [];
        foreach ($context->matches as $match) {
            foreach ($this->one($call, $match, $context, $evaluator) as $result) {
                $out[] = $result;
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function one(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $node = NodeOps::deref(Cands::node($match));
        if (NodeOps::isNull($node) && BuiltinNameEnum::Min->value !== $call->name && BuiltinNameEnum::Max->value !== $call->name) {
            return [$match];
        }

        if (NodeKindEnum::Sequence !== $node->kind && NodeKindEnum::Mapping !== $node->kind) {
            if (NodeOps::isNull($node)) {
                return [];
            }

            throw new EvaluationException(\sprintf('Cannot %s %s', $call->name, '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
        }

        $layout = Cands::dateLayout($context);
        $items  = Traversal::values($match, false);
        switch (BuiltinNameEnum::tryFrom($call->name)) {
            case BuiltinNameEnum::Sort:
                return [Cands::deriveInDocument($this->rebuild($node, ...$this->sorted($items, $layout, $context, $evaluator)), $match)];

            case BuiltinNameEnum::SortBy:
                Args::require($call, 1);

                return [Cands::deriveInDocument($this->rebuild($node, ...$this->sorted($items, $layout, $context, $evaluator, ...$call->arguments)), $match)];

            case BuiltinNameEnum::GroupBy:
                Args::require($call, 1);

                return [Cands::derive($this->groupBy($call, $context, $evaluator, ...$items), $match)];

            case BuiltinNameEnum::Unique:
                return [Cands::derive($this->unique(null, $context, $evaluator, ...$items), $match)];

            case BuiltinNameEnum::UniqueBy:
                Args::require($call, 1);

                return [Cands::derive($this->unique($call, $context, $evaluator, ...$items), $match)];

            case BuiltinNameEnum::Min:
            case BuiltinNameEnum::Max:
                $best = null;
                foreach ($items as $item) {
                    if (!$best instanceof Candidate) {
                        $best = $item;

                        continue;
                    }

                    $order = Compare::order($item->node, $best->node, $layout);
                    if ((BuiltinNameEnum::Min->value === $call->name && $order < 0) || (BuiltinNameEnum::Max->value === $call->name && $order > 0)) {
                        $best = $item;
                    }
                }

                return $best instanceof Candidate ? [$best] : [];

            case BuiltinNameEnum::Reverse:
                if (NodeKindEnum::Sequence !== $node->kind) {
                    return [$match];
                }

                $copies = [];
                foreach (array_reverse($node->content) as $item) {
                    $copies[] = $item->deepCopy();
                }

                $new        = NodeOps::seq($copies);
                $new->style = $node->style;

                return [Cands::derive($new, $match)];

            default:
                $copies = [];
                foreach ($items as $item) {
                    $copies[] = $item->node->deepCopy();
                }

                shuffle($copies);

                return [Cands::deriveInDocument(NodeOps::seq($copies), $match)];
        }
    }

    /**
     * @param list<Candidate> $items
     *
     * @return list<Candidate>
     */
    private function sorted(array $items, ?string $layout, EvaluationContext $context, EvaluatorInterface $evaluator, ExpressionNodeInterface ...$keyExpressions): array
    {
        $read   = $context->withDontAutoCreate(true);
        $keyed  = [];
        foreach ($items as $index => $item) {
            $keys = [];
            if ([] === $keyExpressions) {
                $keys[] = $item->node;
            } else {
                foreach ($keyExpressions as $expression) {
                    foreach ($evaluator->evaluate($expression, $read->withMatches($item)) as $found) {
                        $keys[] = Cands::node($found);
                    }
                }
            }

            $keyed[] = [$index, $item, $keys];
        }

        $single = [];
        foreach ($keyed as $entry) {
            if (1 !== \count($entry[2])) {
                $single = null;

                break;
            }

            $single[] = $entry[2][0];
        }

        $fast = null === $single ? null : $this->nativeOrder($layout, ...$single);
        if (null !== $fast) {
            return array_map(static fn (int $position): Candidate => $keyed[$position][1], $fast);
        }

        usort($keyed, static function (array $a, array $b) use ($layout): int {
            $length = max(\count($a[2]), \count($b[2]));
            for ($i = 0; $i < $length; ++$i) {
                $left  = $a[2][$i] ?? NodeOps::null();
                $right = $b[2][$i] ?? NodeOps::null();
                $order = Compare::order($left, $right, $layout);
                if (0 !== $order) {
                    return $order;
                }
            }

            return $a[0] <=> $b[0];
        });

        return array_map(static fn (array $entry): Candidate => $entry[1], $keyed);
    }

    /**
     * The stable ascending order of the keys, as positions, using PHP's native sort when that gives exactly
     * the order of {@see Compare::order()}: every key a plain string that cannot be a date, or every key a
     * number that is not NaN. Null when the keys are mixed or need the general comparison.
     *
     * Hot path (benchmarks yq:group-medium, yq:group-large): a user-space comparator costs a closure call
     * and several lookups per comparison, which dominated group_by and sort_by on large sequences.
     *
     * @return list<int>|null
     */
    private function nativeOrder(?string $layout, Node ...$keys): ?array
    {
        if (null !== $layout || [] === $keys) {
            return null;
        }

        $values  = [];
        $strings = null;
        foreach (array_values($keys) as $position => $key) {
            $key = NodeOps::deref($key);
            if (NodeKindEnum::Scalar !== $key->kind) {
                return null;
            }

            $tag = NodeOps::effectiveTag($key);
            if (CoreSchema::TAG_INT === $tag || CoreSchema::TAG_FLOAT === $tag) {
                $number = Numbers::of($key);
                if (null === $number || (\is_float($number) && is_nan($number)) || true === $strings) {
                    return null;
                }

                $strings             = false;
                $values[$position]   = $number;

                continue;
            }

            $text = $key->value;
            if (CoreSchema::TAG_STR !== $tag || false === $strings || ('' !== $text && $text[0] >= '0' && $text[0] <= '9')) {
                return null;
            }

            $strings           = true;
            $values[$position] = $text;
        }

        if ($strings) {
            asort($values, \SORT_STRING);
        } else {
            asort($values);
        }

        return array_keys($values);
    }

    private function rebuild(Node $node, Candidate ...$ordered): Node
    {
        if (NodeKindEnum::Mapping === $node->kind) {
            $flat = [];
            foreach ($ordered as $item) {
                $key = $item->key;
                if ($key instanceof Node) {
                    $flat[] = $key->deepCopy();
                    $flat[] = $item->node->deepCopy();
                }
            }

            $new        = NodeOps::map($flat);
            $new->style = $node->style;

            return $new;
        }

        $copies = [];
        foreach ($ordered as $item) {
            $copies[] = $item->node->deepCopy();
        }

        $new        = NodeOps::seq($copies);
        $new->style = $node->style;

        return $new;
    }

    private function groupBy(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator, Candidate ...$items): Node
    {
        $read = $context->withDontAutoCreate(true);

        // The reference groups by the key's scalar text in order of first appearance (it does not sort).
        $groups = [];
        foreach ($items as $item) {
            $results = $evaluator->evaluate($call->arguments[0], $read->withMatches($item));
            $key     = []                   === $results ? NodeOps::null() : Cands::node($results[0]);
            $text    = NodeKindEnum::Scalar === $key->kind ? $key->value : '';

            $groups[$text][] = $item->node->deepCopy();
        }

        return NodeOps::seq(array_map(NodeOps::seq(...), array_values($groups)));
    }

    private function unique(?Call $call, EvaluationContext $context, EvaluatorInterface $evaluator, Candidate ...$items): Node
    {
        $read = $context->withDontAutoCreate(true);
        $seen = [];
        $kept = [];
        foreach ($items as $item) {
            if ($call instanceof Call) {
                $results = $evaluator->evaluate($call->arguments[0], $read->withMatches($item));
                $key     = [] === $results ? 'null' : Compare::canonical(Cands::node($results[0]));
            } else {
                $key = Compare::canonical($item->node);
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[]     = $item->node->deepCopy();
        }

        return NodeOps::seq($kept);
    }
}
