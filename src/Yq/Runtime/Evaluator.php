<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\Bind;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\Ast\Collect;
use LTS\PhpXq\Yq\Expression\Ast\Conditional;
use LTS\PhpXq\Yq\Expression\Ast\Field;
use LTS\PhpXq\Yq\Expression\Ast\Identity;
use LTS\PhpXq\Yq\Expression\Ast\Interpolation;
use LTS\PhpXq\Yq\Expression\Ast\Iterate;
use LTS\PhpXq\Yq\Expression\Ast\Literal;
use LTS\PhpXq\Yq\Expression\Ast\ObjectConstruct;
use LTS\PhpXq\Yq\Expression\Ast\RecursiveDescent;
use LTS\PhpXq\Yq\Expression\Ast\Reduce;
use LTS\PhpXq\Yq\Expression\Ast\Slice;
use LTS\PhpXq\Yq\Expression\Ast\VariableRef;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Interprets the expression AST with yq semantics.
 *
 * Structural nodes are handled here; `Call` and `Binary` dispatch to the operator registry. Matches are
 * {@see Candidate}s (node plus parent and key), so assignment mutates the document in place and
 * `path`, `key` and `parent` know where a match came from. A name that is not a registered operator and
 * takes no arguments is a field name, as the reference lets `a.b` stand for `.a.b`.
 */
final readonly class Evaluator implements EvaluatorInterface
{
    public function __construct(
        private OperatorRegistryInterface $operators = new OperatorRegistry(),
    ) {
    }

    public function evaluate(ExpressionNodeInterface $expression, EvaluationContext $context): array
    {
        if ($expression instanceof Field) {
            return $this->field($expression, $context);
        }

        if ($expression instanceof Binary) {
            $operator = $this->operators->binary($expression->operator);
            if (!$operator instanceof BinaryOperatorInterface) {
                throw new EvaluationException('Unsupported operator ' . $expression->operator->value);
            }

            return $operator->evaluate($expression, $context, $this);
        }

        if ($expression instanceof Identity) {
            return $context->matches;
        }

        if ($expression instanceof Literal) {
            return [Cands::derive($expression->value->deepCopy(), $context->matches[0] ?? null)];
        }

        if ($expression instanceof Call) {
            return $this->call($expression, $context);
        }

        if ($expression instanceof Iterate) {
            return $this->iterate($expression, $context);
        }

        if ($expression instanceof Collect) {
            return $this->collect($expression, $context);
        }

        if ($expression instanceof Conditional) {
            return $this->conditional($expression, $context);
        }

        if ($expression instanceof RecursiveDescent) {
            $out = [];
            foreach ($this->evaluate($expression->base, $context) as $match) {
                Traversal::descend($match, $expression->includeKeys, $out);
            }

            return $out;
        }

        if ($expression instanceof VariableRef) {
            return $this->variable($expression, $context);
        }

        if ($expression instanceof ObjectConstruct) {
            return $this->construct($expression, $context);
        }

        if ($expression instanceof Slice) {
            return $this->slice($expression, $context);
        }

        if ($expression instanceof Bind) {
            $source = $this->evaluate($expression->source, $context);
            $values = $expression->reference ? $source : array_map(Cands::copyOf(...), $source);

            return $this->evaluate($expression->body, $context->withVariable($expression->name, $values));
        }

        if ($expression instanceof Reduce) {
            return $this->reduce($expression, $context);
        }

        if ($expression instanceof Interpolation) {
            return $this->interpolate($expression, $context);
        }

        throw new EvaluationException('Unsupported expression ' . $expression::class);
    }

    /**
     * @return list<Candidate>
     */
    private function call(Call $call, EvaluationContext $context): array
    {
        $operator = $this->operators->call($call->name);
        if ($operator instanceof CallOperatorInterface) {
            return $operator->evaluate($call, $context, $this);
        }

        if ([] !== $call->arguments) {
            throw new EvaluationException('Unknown function ' . $call->name);
        }

        return $this->field(new Field(new Identity(), new Literal(NodeOps::str($call->name))), $context);
    }

    /**
     * @return list<Candidate>
     */
    private function field(Field $field, EvaluationContext $context): array
    {
        $bases = $field->base instanceof Identity ? $context->matches : $this->evaluate($field->base, $context);
        $keys  = $this->keys($field->key, $context);
        $fixed = $context->services->yamlFixMergeAnchorToSpec;
        $auto  = !$context->dontAutoCreate;
        $out   = [];
        foreach ($bases as $base) {
            foreach ($keys as $key) {
                foreach (Traversal::field($base, $key, $fixed, $auto, $field->optional) as $found) {
                    $out[] = $found;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<Node> the scalar keys a key expression stands for
     */
    private function keys(ExpressionNodeInterface $key, EvaluationContext $context): array
    {
        if ($key instanceof Literal) {
            return [$key->value];
        }

        $keys = [];
        foreach ($this->evaluate($key, $context->withDontAutoCreate(true)) as $match) {
            $node = NodeOps::deref(Cands::node($match));
            if (NodeKindEnum::Scalar === $node->kind) {
                $keys[] = $node;
            }
        }

        return $keys;
    }

    /**
     * @return list<Candidate>
     */
    private function iterate(Iterate $iterate, EvaluationContext $context): array
    {
        $bases = $iterate->base instanceof Identity ? $context->matches : $this->evaluate($iterate->base, $context);
        $fixed = $context->services->yamlFixMergeAnchorToSpec;
        $out   = [];
        foreach ($bases as $base) {
            foreach (Traversal::values($base, $fixed) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function slice(Slice $slice, EvaluationContext $context): array
    {
        $bases = $this->evaluate($slice->base, $context);
        $read  = $context->withDontAutoCreate(true);
        $from  = $slice->from instanceof ExpressionNodeInterface ? $this->firstInt($slice->from, $read) : null;
        $to    = $slice->to instanceof ExpressionNodeInterface ? $this->firstInt($slice->to, $read) : null;
        $out   = [];
        foreach ($bases as $base) {
            $node = NodeOps::deref(Cands::node($base));
            if (NodeKindEnum::Sequence === $node->kind) {
                [$start, $end] = $this->bounds($from, $to, \count($node->content));
                $items         = [];
                for ($i = $start; $i < $end; ++$i) {
                    $items[] = $node->content[$i];
                }

                $out[] = Cands::derive(NodeOps::seq($items), $base);

                continue;
            }

            if (NodeKindEnum::Scalar === $node->kind && !NodeOps::isNull($node)) {
                $chars         = mb_str_split($node->value);
                [$start, $end] = $this->bounds($from, $to, \count($chars));
                $out[]         = Cands::derive(NodeOps::str(implode('', \array_slice($chars, $start, max(0, $end - $start)))), $base);

                continue;
            }

            if (!NodeOps::isNull($node)) {
                throw new EvaluationException('Cannot index ' . NodeOps::effectiveTag($node) . ' with a slice');
            }
        }

        return $out;
    }

    /**
     * @return array{int, int}
     */
    private function bounds(?int $from, ?int $to, int $count): array
    {
        $start = $from ?? 0;
        $end   = $to   ?? $count;
        if ($start < 0) {
            $start += $count;
        }

        if ($end < 0) {
            $end += $count;
        }

        $start = max(0, min($count, $start));
        $end   = max($start, min($count, $end));

        return [$start, $end];
    }

    private function firstInt(ExpressionNodeInterface $expression, EvaluationContext $context): ?int
    {
        foreach ($this->evaluate($expression, $context) as $match) {
            $number = Numbers::of(Cands::node($match));
            if (null !== $number) {
                return (int)$number;
            }
        }

        return null;
    }

    /**
     * @return list<Candidate>
     */
    private function collect(Collect $collect, EvaluationContext $context): array
    {
        if ([] === $context->matches) {
            return [new Candidate(NodeOps::seq())];
        }

        $read  = $context->withDontAutoCreate(true);
        $items = [];
        if ($collect->inner instanceof ExpressionNodeInterface) {
            foreach ($context->matches as $match) {
                foreach ($this->evaluate($collect->inner, $read->withMatches([$match])) as $found) {
                    $items[] = $found->node->deepCopy();
                }
            }
        }

        return [Cands::derive(NodeOps::seq($items), $context->matches[0])];
    }

    /**
     * @return list<Candidate>
     */
    private function conditional(Conditional $conditional, EvaluationContext $context): array
    {
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            $single    = $context->withMatches([$match]);
            $decisions = $this->evaluate($conditional->condition, $read->withMatches([$match]));
            if ([] === $decisions) {
                $decisions = [Cands::derive(NodeOps::null(), $match)];
            }

            foreach ($decisions as $decision) {
                if (NodeOps::truthy(Cands::node($decision))) {
                    foreach ($this->evaluate($conditional->then, $single) as $found) {
                        $out[] = $found;
                    }
                } elseif ($conditional->otherwise instanceof ExpressionNodeInterface) {
                    foreach ($this->evaluate($conditional->otherwise, $single) as $found) {
                        $out[] = $found;
                    }
                } else {
                    $out[] = $match;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function variable(VariableRef $reference, EvaluationContext $context): array
    {
        if (isset($context->variables[$reference->name])) {
            return $context->variables[$reference->name];
        }

        if ('ENV' === $reference->name) {
            $flat = [];
            foreach (getenv() as $name => $value) {
                $flat[] = NodeOps::str($name);
                $flat[] = NodeOps::str($value);
            }

            return [Cands::derive(NodeOps::map($flat), $context->matches[0] ?? null)];
        }

        throw new EvaluationException(\sprintf('Variable ${%s} not yet defined', $reference->name));
    }

    /**
     * @return list<Candidate>
     */
    private function construct(ObjectConstruct $construct, EvaluationContext $context): array
    {
        $read    = $context->withDontAutoCreate(true);
        $matches = [] === $context->matches ? [null] : $context->matches;
        $out     = [];
        foreach ($matches as $match) {
            $sub    = $read->withMatches(null === $match ? [] : [$match]);
            $combos = [[]];
            foreach ($construct->entries as $entry) {
                $keys   = $this->evaluate($entry->key, $sub);
                $values = $this->evaluate($entry->value, $sub);
                if ([] === $values) {
                    $values = [Cands::derive(NodeOps::null(), $match)];
                }

                $next = [];
                foreach ($combos as $combo) {
                    foreach ($keys as $key) {
                        foreach ($values as $value) {
                            $next[] = [...$combo, [$key->node, $value->node]];
                        }
                    }
                }

                $combos = $next;
            }

            foreach ($combos as $combo) {
                $flat = [];
                foreach ($combo as [$key, $value]) {
                    $key    = NodeOps::deref(NodeOps::unwrap($key));
                    $flat[] = new Node(NodeKindEnum::Scalar, '' === $key->tag ? '!!str' : $key->tag, NodeStyleEnum::Default, $key->value);
                    $flat[] = $value->deepCopy();
                }

                $out[] = Cands::derive(NodeOps::map($flat), $match);
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function reduce(Reduce $reduce, EvaluationContext $context): array
    {
        $items       = $this->evaluate($reduce->source, $context);
        $accumulator = $this->evaluate($reduce->initial, $context);
        foreach ($items as $item) {
            $accumulator = $this->evaluate($reduce->update, $context->withMatches($accumulator)->withVariable($reduce->name, [$item]));
        }

        return $accumulator;
    }

    /**
     * @return list<Candidate>
     */
    private function interpolate(Interpolation $interpolation, EvaluationContext $context): array
    {
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach ($context->matches as $match) {
            $text = '';
            foreach ($interpolation->parts as $part) {
                if (\is_string($part)) {
                    $text .= $part;

                    continue;
                }

                $results = $this->evaluate($part, $read->withMatches([$match]));
                if ([] === $results) {
                    continue;
                }

                $text .= $this->render($results[0]->node, $context);
            }

            $out[] = Cands::derive(NodeOps::str($text), $match);
        }

        return $out;
    }

    private function render(Node $node, EvaluationContext $context): string
    {
        $node = NodeOps::deref(NodeOps::unwrap($node));
        if (NodeKindEnum::Scalar === $node->kind) {
            return $node->value;
        }

        try {
            return rtrim($context->services->formats->encoder(FormatEnum::Yaml)->encode($node, new FormatOptions(), 0), "\n");
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }
    }
}
