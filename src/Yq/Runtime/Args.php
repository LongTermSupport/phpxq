<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Expression\Ast\Call;

/**
 * Evaluation of an operator's arguments against one match.
 */
final class Args
{
    private function __construct()
    {
    }

    /**
     * The results of argument `$index`, evaluated read-only with `$match` as input.
     *
     * @return list<Candidate>
     */
    public static function results(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): array
    {
        if (!isset($call->arguments[$index])) {
            return [];
        }

        $sub = $context->withDontAutoCreate(true)->withMatches($match instanceof Candidate ? [$match] : []);

        return $evaluator->evaluate($call->arguments[$index], $sub);
    }

    /**
     * The first result of the argument as a node, or null.
     */
    public static function node(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): ?Node
    {
        $results = self::results($call, $index, $context, $evaluator, $match);

        return [] === $results ? null : NodeOps::deref(Cands::node($results[0]));
    }

    /**
     * The first result of the argument as text, or null when it has none or is not a scalar.
     */
    public static function string(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): ?string
    {
        $node = self::node($call, $index, $context, $evaluator, $match);

        return $node instanceof Node && NodeKind::Scalar === $node->kind ? $node->value : null;
    }

    public static function int(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): ?int
    {
        $node = self::node($call, $index, $context, $evaluator, $match);
        if (!$node instanceof Node) {
            return null;
        }

        $number = Numbers::of($node);

        return null === $number ? null : (int) $number;
    }

    /**
     * Every result of the argument as a scalar text.
     *
     * @return list<string>
     */
    public static function strings(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): array
    {
        $out = [];
        foreach (self::results($call, $index, $context, $evaluator, $match) as $result) {
            $node = NodeOps::deref(Cands::node($result));
            if (NodeKind::Scalar === $node->kind) {
                $out[] = $node->value;
            }
        }

        return $out;
    }

    /**
     * Requires an argument; reports the operator when it is missing.
     *
     * @throws EvaluationException
     */
    public static function require(Call $call, int $count): void
    {
        if (\count($call->arguments) < $count) {
            throw new EvaluationException(\sprintf('%s requires %d argument%s', $call->name, $count, 1 === $count ? '' : 's'));
        }
    }
}
