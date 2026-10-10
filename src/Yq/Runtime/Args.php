<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * Evaluation of an operator's arguments against one match.
 *
 * @internal
 */
final readonly class Args
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

        $sub = $context->withDontAutoCreate(true)->withMatches(...($match instanceof Candidate ? [$match] : []));

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

        return $node instanceof Node && NodeKindEnum::Scalar === $node->kind ? $node->value : null;
    }

    /**
     * Like string(), but the empty string stands for an absent or non-scalar argument.
     */
    public static function stringOrEmpty(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): string
    {
        $text = self::string($call, $index, $context, $evaluator, $match);

        return null === $text ? '' : $text;
    }

    public static function int(Call $call, int $index, EvaluationContext $context, EvaluatorInterface $evaluator, ?Candidate $match): ?int
    {
        $node = self::node($call, $index, $context, $evaluator, $match);
        if (!$node instanceof Node) {
            return null;
        }

        return Numbers::intOf($node);
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
            if (NodeKindEnum::Scalar === $node->kind) {
                $out[] = $node->value;
            }
        }

        return $out;
    }

    /**
     * The reference's documentation writes some multi-argument calls with commas (`sub("a", "b")`), which
     * parse as one union argument. A call with fewer than `$minimum` arguments whose only argument is a
     * union is split at the commas.
     */
    public static function split(Call $call, int $minimum): Call
    {
        if (1 !== \count($call->arguments) || $minimum <= 1) {
            return $call;
        }

        $only = $call->arguments[0];
        if (!$only instanceof Binary || BinaryOperatorEnum::Union !== $only->operator) {
            return $call;
        }

        $parts = [];
        self::unionParts($only, $parts);

        return new Call($call->name, $parts);
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

    /**
     * @param list<ExpressionNodeInterface> $parts
     */
    private static function unionParts(ExpressionNodeInterface $node, array &$parts): void
    {
        if ($node instanceof Binary && BinaryOperatorEnum::Union === $node->operator) {
            self::unionParts($node->left, $parts);
            self::unionParts($node->right, $parts);

            return;
        }

        $parts[] = $node;
    }
}
