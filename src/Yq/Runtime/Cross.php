<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * The reference's "cross function": an infix operator evaluates both sides against each match (or against
 * all matches at once when they are the roots of several documents, as in eval-all) and combines every
 * left result with every right result. A side with no result is passed as null.
 *
 * @internal
 */
final readonly class Cross
{
    private function __construct()
    {
    }

    /**
     * The groups of matches evaluated together: one group per match, or a single group in eval-all shape.
     *
     * @return list<list<Candidate>>
     */
    public static function units(EvaluationContext $context): array
    {
        if (Cands::together(...$context->matches)) {
            return [$context->matches];
        }

        $units = [];
        foreach ($context->matches as $match) {
            $units[] = [$match];
        }

        return $units;
    }

    /**
     * @param callable(?Candidate, ?Candidate, ?Candidate): ?Candidate $calculate receives the left and right
     *                                                                            candidate and the context match; null skips
     *
     * @return list<Candidate>
     */
    public static function run(ExpressionNodeInterface $left, ExpressionNodeInterface $right, EvaluationContext $context, EvaluatorInterface $evaluator, callable $calculate): array
    {
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach (self::units($context) as $unit) {
            $sub    = $read->withMatches(...$unit);
            $lefts  = $evaluator->evaluate($left, $sub);
            $rights = $evaluator->evaluate($right, $sub);
            $from   = $unit[0] ?? null;
            if ([] === $lefts && [] === $rights) {
                $result = $calculate(null, null, $from);
                if ($result instanceof Candidate) {
                    $out[] = $result;
                }

                continue;
            }

            if ([] === $lefts) {
                $lefts = [null];
            }

            if ([] === $rights) {
                $rights = [null];
            }

            foreach ($lefts as $l) {
                foreach ($rights as $r) {
                    $result = $calculate($l, $r, $from);
                    if ($result instanceof Candidate) {
                        $out[] = $result;
                    }
                }
            }
        }

        return $out;
    }
}
