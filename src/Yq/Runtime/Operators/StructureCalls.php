<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Numbers;
use LTS\PhpXq\Yq\Runtime\PathOps;

/**
 * Operators that reshape a tree: `pick`, `omit`, `del` (`delete`), `delpaths`, `setpath`.
 *
 * @internal
 */
final readonly class StructureCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return BuiltinNameEnum::values(
            BuiltinNameEnum::Pick,
            BuiltinNameEnum::Omit,
            BuiltinNameEnum::Del,
            BuiltinNameEnum::Delete,
            BuiltinNameEnum::Delpaths,
            BuiltinNameEnum::Setpath,
        );
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $call = Args::split($call, BuiltinNameEnum::Setpath->value === $call->name ? 2 : 1);
        Args::require($call, BuiltinNameEnum::Setpath->value === $call->name ? 2 : 1);
        $fixed = $context->services->yamlFixMergeAnchorToSpec;
        switch (BuiltinNameEnum::tryFrom($call->name)) {
            case BuiltinNameEnum::Del:
            case BuiltinNameEnum::Delete:
                PathOps::delete(...$evaluator->evaluate($call->arguments[0], $context->withDontAutoCreate(true)));

                return $context->matches;

            case BuiltinNameEnum::Delpaths:
                $targets = [];
                foreach ($context->matches as $match) {
                    foreach (Args::results($call, 0, $context, $evaluator, $match) as $paths) {
                        $list = NodeOps::deref(Cands::node($paths));
                        foreach (NodeKindEnum::Sequence === $list->kind ? $list->content : [] as $path) {
                            $target = PathOps::follow($match, false, $fixed, ...PathOps::elements($path));
                            if ($target instanceof Candidate) {
                                $targets[] = $target;
                            }
                        }
                    }
                }

                PathOps::delete(...$targets);

                return $context->matches;

            case BuiltinNameEnum::Setpath:
                foreach ($context->matches as $match) {
                    $path   = Args::node($call, 0, $context, $evaluator, $match);
                    $values = Args::results($call, 1, $context, $evaluator, $match);
                    $value  = [] === $values ? NodeOps::null() : Cands::node($values[0]);
                    if ($path instanceof Node) {
                        PathOps::set($match, $value, $fixed, ...PathOps::elements($path));
                    }
                }

                return $context->matches;

            default:
                $out = [];
                foreach ($context->matches as $match) {
                    $out[] = Cands::deriveInDocument($this->pickOrOmit($call, $match, $context, $evaluator), $match);
                }

                return $out;
        }
    }

    private function pickOrOmit(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): Node
    {
        $node = NodeOps::deref(Cands::node($match));
        $keys = [];
        foreach (Args::results($call, 0, $context, $evaluator, $match) as $result) {
            $key = NodeOps::deref(Cands::node($result));
            if (NodeKindEnum::Sequence === $key->kind) {
                foreach ($key->content as $item) {
                    $keys[] = NodeOps::deref($item);
                }
            } else {
                $keys[] = $key;
            }
        }

        $pick = BuiltinNameEnum::Pick->value === $call->name;
        if (NodeKindEnum::Mapping === $node->kind) {
            return $this->mapping($node, $pick, ...$keys);
        }

        if (NodeKindEnum::Sequence === $node->kind) {
            return $this->sequence($node, $pick, ...$keys);
        }

        throw new EvaluationException(\sprintf('Cannot %s from %s', $call->name, '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
    }

    private function mapping(Node $node, bool $pick, Node ...$keys): Node
    {
        $flat = [];
        if ($pick) {
            foreach ($keys as $key) {
                for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
                    if ($node->content[$i]->value === $key->value) {
                        $flat[] = $node->content[$i]->deepCopy();
                        $flat[] = $node->content[$i + 1]->deepCopy();

                        break;
                    }
                }
            }
        } else {
            $drop = [];
            foreach ($keys as $key) {
                $drop[$key->value] = true;
            }

            for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
                if (!isset($drop[$node->content[$i]->value])) {
                    $flat[] = $node->content[$i]->deepCopy();
                    $flat[] = $node->content[$i + 1]->deepCopy();
                }
            }
        }

        $new        = NodeOps::map($flat);
        $new->style = $node->style;

        return $new;
    }

    private function sequence(Node $node, bool $pick, Node ...$keys): Node
    {
        $count   = \count($node->content);
        $indices = [];
        foreach ($keys as $key) {
            $number = Numbers::of($key);
            if (!\is_int($number)) {
                continue;
            }

            if ($number < 0) {
                $number += $count;
            }

            if ($number >= 0 && $number < $count) {
                $indices[] = $number;
            }
        }

        $items = [];
        if ($pick) {
            foreach (array_unique($indices) as $index) {
                $items[] = $node->content[$index]->deepCopy();
            }
        } else {
            $drop = array_flip($indices);
            foreach ($node->content as $index => $item) {
                if (!isset($drop[$index])) {
                    $items[] = $item->deepCopy();
                }
            }
        }

        $new        = NodeOps::seq($items);
        $new->style = $node->style;

        return $new;
    }
}
