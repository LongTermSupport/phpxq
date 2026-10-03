<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Detached;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Traversal;

/**
 * Collection-shaping operators: `length`, `keys`, `to_entries`, `from_entries`, `with_entries`, `map`,
 * `map_values`, `flatten`, `add`, `pivot`, `array_to_map`, `range`.
 */
final class CollectionCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['length', 'keys', 'to_entries', 'from_entries', 'with_entries', 'map', 'map_values', 'flatten', 'add', 'pivot', 'array_to_map', 'range'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        if ('map_values' === $call->name) {
            return $this->mapValues($call, $context, $evaluator);
        }

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
        switch ($call->name) {
            case 'length':
                return [Cands::derive(NodeOps::int(self::length($node)), $match)];

            case 'keys':
                return [Cands::derive(self::keys($node), $match)];

            case 'to_entries':
                return NodeOps::isNull($node) ? [] : [Cands::derive(self::toEntries($node, $match), $match)];

            case 'from_entries':
                return [Cands::derive(self::fromEntries($node), $match)];

            case 'with_entries':
                return $this->withEntries($call, $match, $node, $context, $evaluator);

            case 'map':
                return $this->map($call, $match, $node, $context, $evaluator);

            case 'flatten':
                $depth = [] === $call->arguments ? -1 : (Args::int($call, 0, $context, $evaluator, $match) ?? -1);
                if (NodeKind::Sequence !== $node->kind) {
                    throw new EvaluationException('Cannot flatten ' . $node->tag);
                }

                $flat = [];
                self::flatten($node, $depth, $flat);
                $new        = NodeOps::seq($flat);
                $new->style = $node->style;

                return [Cands::derive($new, $match)];

            case 'add':
                return $this->add($call, $match, $node, $context, $evaluator);

            case 'pivot':
                return [Cands::derive(self::pivot($node), $match)];

            case 'array_to_map':
                return [Cands::derive(self::arrayToMap($node), $match)];

            default:
                return $this->range($call, $match, $context, $evaluator);
        }
    }

    private static function length(Node $node): int
    {
        return match ($node->kind) {
            NodeKind::Mapping  => intdiv(\count($node->content), 2),
            NodeKind::Sequence => \count($node->content),
            default            => NodeOps::isNull($node) ? 0 : mb_strlen($node->value),
        };
    }

    private static function keys(Node $node): Node
    {
        if (NodeKind::Mapping === $node->kind) {
            $keys = [];
            for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
                $key    = $node->content[$i];
                $keys[] = new Node(NodeKind::Scalar, $key->tag, NodeStyle::Default, $key->value);
            }

            return NodeOps::seq($keys);
        }

        if (NodeKind::Sequence === $node->kind) {
            $keys = [];
            foreach (array_keys($node->content) as $index) {
                $keys[] = NodeOps::int($index);
            }

            return NodeOps::seq($keys);
        }

        throw new EvaluationException('Cannot get keys of ' . ('' === $node->tag ? NodeOps::kindName($node) : $node->tag) . ', keys only works on maps and arrays');
    }

    private static function toEntries(Node $node, Candidate $match): Node
    {
        $entries = [];
        foreach (Traversal::values($match, false) as $child) {
            $key       = $child->key;
            $keyCopy   = $key instanceof Node ? new Node(NodeKind::Scalar, $key->tag, NodeStyle::Default, $key->value) : NodeOps::null();
            $entries[] = NodeOps::map([NodeOps::str('key'), $keyCopy, NodeOps::str('value'), $child->node]);
        }

        if (NodeKind::Mapping !== $node->kind && NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException('Cannot get entries of ' . $node->tag);
        }

        return NodeOps::seq($entries);
    }

    private static function fromEntries(Node $node): Node
    {
        if (NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException('Cannot convert ' . $node->tag . ' from entries');
        }

        $flat = [];
        foreach ($node->content as $entry) {
            $entry = NodeOps::deref($entry);
            if (NodeKind::Mapping !== $entry->kind) {
                continue;
            }

            $key   = null;
            $value = null;
            for ($i = 0, $n = \count($entry->content); $i < $n; $i += 2) {
                if ('key' === $entry->content[$i]->value) {
                    $key = $entry->content[$i + 1];
                } elseif ('value' === $entry->content[$i]->value) {
                    $value = $entry->content[$i + 1];
                }
            }

            if (!$key instanceof Node) {
                continue;
            }

            $key    = NodeOps::deref($key);
            $flat[] = new Node(NodeKind::Scalar, $key->tag, NodeStyle::Default, $key->value);
            $flat[] = $value instanceof Node ? $value : NodeOps::null();
        }

        return NodeOps::map($flat);
    }

    /**
     * @return list<Candidate>
     */
    private function withEntries(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        if (NodeOps::isNull($node)) {
            return [];
        }

        $entries    = self::toEntries($node, $match);
        $entriesCan = Cands::derive($entries, $match);
        $read       = $context->withDontAutoCreate(true);
        $kept       = [];
        foreach (Traversal::values($entriesCan, false) as $entry) {
            foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$entry])) as $result) {
                $kept[] = $result->node;
            }
        }

        return [Cands::derive(self::fromEntries(NodeOps::seq($kept)), $match)];
    }

    /**
     * @return list<Candidate>
     */
    private function map(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        if (NodeKind::Mapping !== $node->kind && NodeKind::Sequence !== $node->kind) {
            return [];
        }

        $read  = $context->withDontAutoCreate(true);
        $items = [];
        foreach (Traversal::values($match, false) as $child) {
            foreach ($evaluator->evaluate($call->arguments[0], $read->withMatches([$child])) as $result) {
                $items[] = $result->node->deepCopy();
            }
        }

        $new = NodeOps::seq($items);
        if (NodeKind::Sequence === $node->kind) {
            $new->style = $node->style;
        }

        return [Cands::derive($new, $match)];
    }

    /**
     * @return list<Candidate>
     */
    private function mapValues(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $read = $context->withDontAutoCreate(true);
        foreach ($context->matches as $match) {
            foreach (Traversal::values($match, false) as $child) {
                $results = $evaluator->evaluate($call->arguments[0], $read->withMatches([$child]));
                if ([] !== $results) {
                    Detached::attach($child);
                    NodeOps::updateFrom($child->node, $results[0]->node);
                }
            }
        }

        return $context->matches;
    }

    /**
     * @param list<Node> $out
     */
    private static function flatten(Node $seq, int $depth, array &$out): void
    {
        foreach ($seq->content as $item) {
            $target = NodeOps::deref($item);
            if (NodeKind::Sequence === $target->kind && 0 !== $depth) {
                self::flatten($target, $depth - 1, $out);
            } else {
                $out[] = $item->deepCopy();
            }
        }
    }

    /**
     * @return list<Candidate>
     */
    private function add(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $items = [];
        if ([] !== $call->arguments) {
            foreach (Args::results($call, 0, $context, $evaluator, $match) as $result) {
                $items[] = $result->node;
            }
        } elseif (NodeKind::Sequence === $node->kind) {
            $items = $node->content;
        } elseif (NodeKind::Mapping === $node->kind) {
            for ($i = 1, $n = \count($node->content); $i < $n; $i += 2) {
                $items[] = $node->content[$i];
            }
        } elseif (NodeOps::isNull($node)) {
            return [];
        } else {
            throw new EvaluationException('Cannot add up ' . $node->tag);
        }

        $total  = null;
        $layout = Cands::dateLayout($context);
        foreach ($items as $item) {
            $total = ArithmeticOperator::apply(BinaryOperator::Add, $total, $item, '', $layout);
        }

        return $total instanceof Node ? [Cands::derive($total, $match)] : [];
    }

    private static function pivot(Node $node): Node
    {
        if (NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException('Cannot pivot ' . $node->tag);
        }

        $rows = [];
        foreach ($node->content as $row) {
            $rows[] = NodeOps::deref($row);
        }

        if ([] !== $rows && NodeKind::Mapping === $rows[0]->kind) {
            $columns = [];
            $order   = [];
            foreach ($rows as $index => $row) {
                for ($i = 0, $n = \count($row->content); $i < $n; $i += 2) {
                    $name = $row->content[$i]->value;
                    if (!isset($columns[$name])) {
                        $columns[$name] = [];
                        $order[]        = $row->content[$i];
                    }

                    $columns[$name][$index] = $row->content[$i + 1];
                }
            }

            $flat = [];
            foreach ($order as $key) {
                $cells = [];
                foreach (array_keys($rows) as $index) {
                    $cells[] = isset($columns[$key->value][$index]) ? $columns[$key->value][$index]->deepCopy() : NodeOps::emptyNull();
                }

                $flat[] = $key->deepCopy();
                $flat[] = NodeOps::seq($cells);
            }

            return NodeOps::map($flat);
        }

        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, \count($row->content));
        }

        $columns = [];
        for ($c = 0; $c < $width; ++$c) {
            $cells = [];
            foreach ($rows as $row) {
                $cells[] = isset($row->content[$c]) ? $row->content[$c]->deepCopy() : NodeOps::emptyNull();
            }

            $columns[] = NodeOps::seq($cells);
        }

        return NodeOps::seq($columns);
    }

    private static function arrayToMap(Node $node): Node
    {
        if (NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException('Cannot convert ' . $node->tag . ' to a map');
        }

        $flat = [];
        foreach ($node->content as $index => $item) {
            if (NodeOps::isNull(NodeOps::deref($item))) {
                continue;
            }

            $flat[] = NodeOps::int($index);
            $flat[] = $item->deepCopy();
        }

        return NodeOps::map($flat);
    }

    /**
     * @return list<Candidate>
     */
    private function range(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $from = 0;
        $to   = Args::int($call, 0, $context, $evaluator, $match) ?? 0;
        $step = 1;
        if (\count($call->arguments) >= 2) {
            $from = $to;
            $to   = Args::int($call, 1, $context, $evaluator, $match) ?? 0;
        }

        if (\count($call->arguments) >= 3) {
            $step = Args::int($call, 2, $context, $evaluator, $match) ?? 1;
        }

        $out = [];
        if (0 === $step) {
            return $out;
        }

        for ($i = $from; $step > 0 ? $i < $to : $i > $to; $i += $step) {
            $out[] = Cands::derive(NodeOps::int($i), $match);
        }

        return $out;
    }
}
