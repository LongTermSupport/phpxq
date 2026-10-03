<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Traversal;

/**
 * Ordering operators: `sort`, `sort_by`, `group_by`, `unique`, `unique_by`, `min`, `max`, `reverse`,
 * `shuffle`. On a mapping `sort` and `sort_by` reorder the entries.
 */
final class SortingCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['sort', 'sort_by', 'group_by', 'unique', 'unique_by', 'min', 'max', 'reverse', 'shuffle'];
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
        if (NodeOps::isNull($node) && 'min' !== $call->name && 'max' !== $call->name) {
            return [$match];
        }

        if (NodeKind::Sequence !== $node->kind && NodeKind::Mapping !== $node->kind) {
            if (NodeOps::isNull($node)) {
                return [];
            }

            throw new EvaluationException(\sprintf('Cannot %s %s', $call->name, '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
        }

        $layout = Cands::dateLayout($context);
        $items  = Traversal::values($match, false);
        switch ($call->name) {
            case 'sort':
                return [Cands::derive(self::rebuild($node, self::sorted($items, [], $layout, $context, $evaluator)), $match)];

            case 'sort_by':
                Args::require($call, 1);

                return [Cands::derive(self::rebuild($node, self::sorted($items, $call->arguments, $layout, $context, $evaluator)), $match)];

            case 'group_by':
                Args::require($call, 1);

                return [Cands::derive($this->groupBy($items, $call, $layout, $context, $evaluator), $match)];

            case 'unique':
                return [Cands::derive($this->unique($items, null, $context, $evaluator), $match)];

            case 'unique_by':
                Args::require($call, 1);

                return [Cands::derive($this->unique($items, $call, $context, $evaluator), $match)];

            case 'min':
            case 'max':
                $best = null;
                foreach ($items as $item) {
                    if (!$best instanceof Candidate) {
                        $best = $item;

                        continue;
                    }

                    $order = Compare::order($item->node, $best->node, $layout);
                    if (('min' === $call->name && $order < 0) || ('max' === $call->name && $order > 0)) {
                        $best = $item;
                    }
                }

                return $best instanceof Candidate ? [$best] : [];

            case 'reverse':
                if (NodeKind::Sequence !== $node->kind) {
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

                return [Cands::derive(NodeOps::seq($copies), $match)];
        }
    }

    /**
     * @param list<Candidate>       $items
     * @param list<\LTS\PhpXq\Yq\Expression\ExpressionNode> $keyExpressions
     *
     * @return list<Candidate>
     */
    private static function sorted(array $items, array $keyExpressions, ?string $layout, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $read   = $context->withDontAutoCreate(true);
        $keyed  = [];
        foreach ($items as $index => $item) {
            $keys = [];
            if ([] === $keyExpressions) {
                $keys[] = $item->node;
            } else {
                foreach ($keyExpressions as $expression) {
                    foreach ($evaluator->evaluate($expression, $read->withMatches([$item])) as $found) {
                        $keys[] = Cands::node($found);
                    }
                }
            }

            $keyed[] = [$index, $item, $keys];
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
     * @param list<Candidate> $ordered
     */
    private static function rebuild(Node $node, array $ordered): Node
    {
        if (NodeKind::Mapping === $node->kind) {
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

    /**
     * @param list<Candidate> $items
     */
    private function groupBy(array $items, Call $call, ?string $layout, EvaluationContext $context, EvaluatorInterface $evaluator): Node
    {
        $read  = $context->withDontAutoCreate(true);
        $keyed = [];
        foreach ($items as $index => $item) {
            $results = $evaluator->evaluate($call->arguments[0], $read->withMatches([$item]));
            $key     = [] === $results ? NodeOps::null() : Cands::node($results[0]);
            $keyed[] = [$index, $item, $key];
        }

        usort($keyed, static function (array $a, array $b) use ($layout): int {
            $order = Compare::order($a[2], $b[2], $layout);

            return 0 !== $order ? $order : $a[0] <=> $b[0];
        });

        $groups = [];
        $last   = null;
        $index  = -1;
        foreach ($keyed as [, $item, $key]) {
            if (!$last instanceof Node || NodeOps::isNull($last) !== NodeOps::isNull($key) || !Compare::deepEquals($last, $key)) {
                $groups[] = [];
                ++$index;
                $last = $key;
            }

            $groups[$index][] = $item->node->deepCopy();
        }

        return NodeOps::seq(array_map(static fn (array $group): Node => NodeOps::seq($group), $groups));
    }

    /**
     * @param list<Candidate> $items
     */
    private function unique(array $items, ?Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): Node
    {
        $read = $context->withDontAutoCreate(true);
        $seen = [];
        $kept = [];
        foreach ($items as $item) {
            if ($call instanceof Call) {
                $results = $evaluator->evaluate($call->arguments[0], $read->withMatches([$item]));
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
