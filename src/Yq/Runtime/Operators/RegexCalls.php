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
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\GoRegex;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Regular-expression operators: `test`, `match`, `capture` and `sub`, with Go (RE2) syntax.
 */
final class RegexCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['test', 'match', 'capture', 'sub'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $call = Args::split($call, 'sub' === $call->name ? 2 : 1);
        Args::require($call, 1);
        $out = [];
        foreach ($context->matches as $match) {
            $node = NodeOps::deref(Cands::node($match));
            if (NodeKind::Scalar !== $node->kind) {
                throw new EvaluationException(\sprintf('cannot use %s on a %s', $call->name, NodeOps::kindName($node)));
            }

            $pattern = Args::string($call, 0, $context, $evaluator, $match) ?? '';
            $flagArg = 'sub' === $call->name ? 2 : 1;
            $flags   = Args::string($call, $flagArg, $context, $evaluator, $match) ?? '';
            $regex   = GoRegex::compile($pattern, $flags);
            foreach ($this->one($call, $match, $node, $regex, $flags, $context, $evaluator) as $result) {
                $out[] = $result;
            }
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function one(Call $call, Candidate $match, Node $node, string $regex, string $flags, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $global = str_contains($flags, 'g');
        switch ($call->name) {
            case 'test':
                return [Cands::derive(NodeOps::bool(GoRegex::test($regex, $node->value)), $match)];

            case 'match':
                $out = [];
                foreach (GoRegex::matches($regex, $node->value, $global) as $record) {
                    $out[] = Cands::derive($this->record($record), $match);
                }

                return $out;

            case 'capture':
                $flat = [];
                foreach (GoRegex::matches($regex, $node->value, false) as $record) {
                    foreach ($record['captures'] as $capture) {
                        if ('' === $capture['name']) {
                            continue;
                        }

                        $flat[] = NodeOps::str($capture['name']);
                        $flat[] = null === $capture['string'] ? NodeOps::null() : NodeOps::str($capture['string']);
                    }
                }

                return [Cands::derive(NodeOps::map($flat), $match)];

            default:
                Args::require($call, 2);
                $replacement = Args::string($call, 1, $context, $evaluator, $match) ?? '';
                $out         = $node->deepCopy();
                $out->value  = GoRegex::replace($regex, $replacement, $node->value, !str_contains($flags, 'n'));
                $out->anchor = '';

                return [Cands::derive($out, $match)];
        }
    }

    /**
     * @param array{string: string, offset: int, length: int, captures: list<array{string: ?string, offset: int, length: int, name: string}>} $record
     */
    private function record(array $record): Node
    {
        $captures = [];
        foreach ($record['captures'] as $capture) {
            $flat = [
                NodeOps::str('string'), null === $capture['string'] ? NodeOps::null() : NodeOps::str($capture['string']),
                NodeOps::str('offset'), NodeOps::int($capture['offset']),
                NodeOps::str('length'), NodeOps::int($capture['length']),
            ];
            if ('' !== $capture['name']) {
                $flat[] = NodeOps::str('name');
                $flat[] = NodeOps::str($capture['name']);
            }

            $captures[] = NodeOps::map($flat);
        }

        return NodeOps::map([
            NodeOps::str('string'), NodeOps::str($record['string']),
            NodeOps::str('offset'), NodeOps::int($record['offset']),
            NodeOps::str('length'), NodeOps::int($record['length']),
            NodeOps::str('captures'), NodeOps::seq($captures),
        ]);
    }
}
