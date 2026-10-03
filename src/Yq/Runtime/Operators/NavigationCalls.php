<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\PathOps;

/**
 * Operators about where a match lives: `parent`, `parents`, `root`, `key`, `is_key`, `path`, `getpath`,
 * `line`, `column`, `document_index` (`di`), `file_index` (`fi`), `filename`, `split_doc`, and the operator
 * that parses and runs an expression held in a string.
 */
final class NavigationCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['parent', 'parents', 'root', 'key', 'is_key', 'path', 'getpath', 'line', 'column', 'document_index', 'di', 'file_index', 'fi', 'filename', 'split_doc', 'splitDoc', 'eval'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $out = [];
        if ('split_doc' === $call->name || 'splitDoc' === $call->name) {
            foreach ($context->matches as $match) {
                if (NodeOps::isNull(Cands::node($match))) {
                    continue;
                }

                $out[] = Cands::withDocument($match, \count($out));
            }

            return $out;
        }

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
        switch ($call->name) {
            case 'parent':
                $count  = [] === $call->arguments ? 1 : (Args::int($call, 0, $context, $evaluator, $match) ?? 1);
                $parent = $this->ancestor($this->ancestors($match), $count);

                return $parent instanceof Candidate ? [$parent] : [];

            case 'parents':
                $items = [];
                foreach ($this->ancestors($match) as $ancestor) {
                    $items[] = $ancestor->node->deepCopy();
                }

                return [Cands::derive(NodeOps::seq($items), $match)];

            case 'root':
                return [Cands::root($match)];

            case 'key':
                return $match->key instanceof Node ? [new Candidate($match->key, $match->parent, $match->key, $match->documentIndex, $match->fileIndex, $match->filename)] : [];

            case 'is_key':
                return [Cands::derive(NodeOps::bool(Cands::isKey($match)), $match)];

            case 'path':
                return [Cands::derive(PathOps::pathNode($match), $match)];

            case 'getpath':
                Args::require($call, 1);
                $found = [];
                foreach (Args::results($call, 0, $context, $evaluator, $match) as $path) {
                    $target = PathOps::follow($match, PathOps::elements(Cands::node($path)), false, $context->services->yamlFixMergeAnchorToSpec);
                    if ($target instanceof Candidate) {
                        $found[] = $target;
                    }
                }

                return $found;

            case 'line':
                return [Cands::derive(NodeOps::int(Cands::node($match)->line), $match)];

            case 'column':
                return [Cands::derive(NodeOps::int(Cands::node($match)->column), $match)];

            case 'document_index':
            case 'di':
                return [Cands::derive(NodeOps::int($match->documentIndex), $match)];

            case 'file_index':
            case 'fi':
                return [Cands::derive(NodeOps::int($match->fileIndex), $match)];

            case 'filename':
                return [Cands::derive(NodeOps::str($match->filename), $match)];

            default:
                return $this->runExpressionText($call, $match, $context, $evaluator);
        }
    }

    /**
     * @return list<Candidate> nearest first
     */
    private function ancestors(Candidate $match): array
    {
        $chain = [];
        for ($parent = $match->parent; $parent instanceof Candidate && NodeKindEnum::Document !== $parent->node->kind; $parent = $parent->parent) {
            $chain[] = $parent;
        }

        return $chain;
    }

    /**
     * @param list<Candidate> $chain
     */
    private function ancestor(array $chain, int $count): ?Candidate
    {
        if (0 === $count) {
            return null;
        }

        $index = $count > 0 ? $count - 1 : \count($chain) + $count;

        return $chain[$index] ?? null;
    }

    /**
     * @return list<Candidate>
     */
    private function runExpressionText(Call $call, Candidate $match, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $out = [];
        foreach (Args::strings($call, 0, $context, $evaluator, $match) as $expression) {
            try {
                $parsed = $context->services->expressionParser->parse($expression);
            } catch (ExpressionSyntaxException $exception) {
                throw new EvaluationException($exception->getMessage(), 0, $exception);
            }

            foreach ($evaluator->evaluate($parsed, $context->withMatches([$match])) as $result) {
                $out[] = $result;
            }
        }

        return $out;
    }
}
