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
use LTS\PhpXq\Yq\Runtime\Numbers;
use LTS\PhpXq\Yq\Runtime\Traversal;

/**
 * Filtering and testing operators: `select`, `not`, `has`, `contains`, `any`, `all`, `any_c`, `all_c`,
 * `first`, `last`, `filter`, `with`, `empty`.
 */
final class SelectionCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['select', 'not', 'has', 'contains', 'any', 'all', 'any_c', 'all_c', 'first', 'last', 'filter', 'with', 'empty'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        return match ($call->name) {
            'select'  => $this->select($call, $context, $evaluator),
            'not'     => $this->not($context),
            'has'     => $this->has($call, $context, $evaluator),
            'contains' => $this->contains($call, $context, $evaluator),
            'any', 'all', 'any_c', 'all_c' => $this->anyAll($call, $context, $evaluator),
            'first', 'last' => $this->firstLast($call, $context, $evaluator),
            'filter'  => $this->filter($call, $context, $evaluator),
            'with'    => $this->with($call, $context, $evaluator),
            default   => [],
        };
    }

    /**
     * @return list<Candidate>
     */
    private function select(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$match])) as $result) {
                if (NodeOps::truthy(Cands::node($result))) {
                    $out[] = $match;

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function not(EvaluationContext $context): array
    {
        $out = [];
        foreach ($context->matches as $match) {
            $out[] = Cands::derive(NodeOps::bool(!NodeOps::truthy(Cands::node($match))), $match);
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function has(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $out = [];
        foreach ($context->matches as $match) {
            $node = NodeOps::deref(Cands::node($match));
            foreach (Args::results($call, 0, $context, $evaluator, $match) as $key) {
                $wanted = NodeOps::deref(Cands::node($key));
                $out[]  = Cands::derive(NodeOps::bool(self::hasKey($node, $wanted)), $match);
            }
        }

        return $out;
    }

    private static function hasKey(Node $node, Node $key): bool
    {
        if (NodeKind::Mapping === $node->kind) {
            for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
                if ($node->content[$i]->value === $key->value) {
                    return true;
                }
            }

            return false;
        }

        if (NodeKind::Sequence === $node->kind) {
            $index = Numbers::of($key);

            return \is_int($index) && $index >= 0 && $index < \count($node->content);
        }

        return false;
    }

    /**
     * @return list<Candidate>
     */
    private function contains(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $out = [];
        foreach ($context->matches as $match) {
            $node = NodeOps::deref(Cands::node($match));
            foreach (Args::results($call, 0, $context, $evaluator, $match) as $wanted) {
                $out[] = Cands::derive(NodeOps::bool(self::containsNode($node, NodeOps::deref(Cands::node($wanted)))), $match);
            }
        }

        return $out;
    }

    private static function containsNode(Node $left, Node $right): bool
    {
        $left  = NodeOps::deref($left);
        $right = NodeOps::deref($right);
        if ($left->kind !== $right->kind) {
            return false;
        }

        switch ($left->kind) {
            case NodeKind::Scalar:
                if (NodeOps::isNull($left) || NodeOps::isNull($right)) {
                    return NodeOps::isNull($left) && NodeOps::isNull($right);
                }

                if (str_contains($left->value, $right->value)) {
                    return true;
                }

                return Compare::deepEquals($left, $right);

            case NodeKind::Sequence:
                foreach ($right->content as $wanted) {
                    $found = false;
                    foreach ($left->content as $item) {
                        if (self::containsNode($item, $wanted)) {
                            $found = true;

                            break;
                        }
                    }

                    if (!$found) {
                        return false;
                    }
                }

                return true;

            case NodeKind::Mapping:
                for ($i = 0, $n = \count($right->content); $i < $n; $i += 2) {
                    $found = false;
                    for ($j = 0, $m = \count($left->content); $j < $m; $j += 2) {
                        if ($left->content[$j]->value === $right->content[$i]->value) {
                            $found = self::containsNode($left->content[$j + 1], $right->content[$i + 1]);

                            break;
                        }
                    }

                    if (!$found) {
                        return false;
                    }
                }

                return true;

            default:
                return false;
        }
    }

    /**
     * @return list<Candidate>
     */
    private function anyAll(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $all       = 'all' === $call->name || 'all_c' === $call->name;
        $condition = str_ends_with($call->name, '_c');
        if ($condition) {
            Args::require($call, 1);
        }

        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            $node = NodeOps::deref(Cands::node($match));
            if (NodeKind::Sequence !== $node->kind) {
                throw new EvaluationException(\sprintf('Cannot apply %s to %s', $call->name, $node->tag));
            }

            $result = $all;
            foreach (Traversal::values($match, false) as $item) {
                $truthy = false;
                if ($condition) {
                    foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$item])) as $found) {
                        $truthy = NodeOps::truthy(Cands::node($found));

                        break;
                    }
                } else {
                    $truthy = NodeOps::truthy(Cands::node($item));
                }

                if ($all && !$truthy) {
                    $result = false;

                    break;
                }

                if (!$all && $truthy) {
                    $result = true;

                    break;
                }
            }

            $out[] = Cands::derive(NodeOps::bool($result), $match);
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function firstLast(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            $node = NodeOps::deref(Cands::node($match));
            if (NodeKind::Mapping !== $node->kind && NodeKind::Sequence !== $node->kind) {
                continue;
            }

            $children = Traversal::values($match, false);
            if ('last' === $call->name) {
                $children = array_reverse($children);
            }

            if ([] === $call->arguments) {
                if ([] !== $children) {
                    $out[] = $children[0];
                }

                continue;
            }

            foreach ($children as $child) {
                foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$child])) as $found) {
                    if (NodeOps::truthy(Cands::node($found))) {
                        $out[] = $child;

                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function filter(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            $items = [];
            foreach (Traversal::values($match, false) as $child) {
                foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$child])) as $found) {
                    if (NodeOps::truthy(Cands::node($found))) {
                        $items[] = $child->node->deepCopy();

                        break;
                    }
                }
            }

            $out[] = Cands::derive(NodeOps::seq($items), $match);
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function with(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $call = Args::split($call, 2);
        Args::require($call, 2);
        $write = $context->withDontAutoCreate(false);
        foreach ($evaluator->evaluate($call->arguments[0], $write) as $target) {
            $evaluator->evaluate($call->arguments[1], $write->withMatches([$target]));
        }

        return $context->matches;
    }
}
