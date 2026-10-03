<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Expression\Ast\Field;
use LTS\PhpXq\Yq\Expression\Ast\Identity;
use LTS\PhpXq\Yq\Expression\Ast\Iterate;
use LTS\PhpXq\Yq\Expression\Ast\Literal;
use LTS\PhpXq\Yq\Expression\Ast\VariableRef;
use LTS\PhpXq\Yq\Expression\ExpressionNode;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;

/**
 * A just-enough evaluator for CLI tests: `.`, `.a.b`, `.[]`, literals and `$variables`.
 */
final class FakeEvaluator implements EvaluatorInterface
{
    /** @var list<EvaluationContext> */
    public array $contexts = [];

    public function evaluate(ExpressionNode $expression, EvaluationContext $context): array
    {
        $this->contexts[] = $context;

        return $this->walk($expression, $context);
    }

    /**
     * @return list<Candidate>
     */
    private function walk(ExpressionNode $expression, EvaluationContext $context): array
    {
        if ($expression instanceof Identity) {
            return $context->matches;
        }

        if ($expression instanceof Literal) {
            return [new Candidate($expression->value)];
        }

        if ($expression instanceof VariableRef) {
            return $context->variables[$expression->name] ?? throw new EvaluationException('unbound variable $' . $expression->name);
        }

        if ($expression instanceof Field && $expression->key instanceof Literal) {
            $out = [];
            foreach ($this->walk($expression->base, $context) as $parent) {
                $out[] = $this->lookup($parent, $expression->key->value->value);
            }

            return $out;
        }

        if ($expression instanceof Iterate) {
            $out = [];
            foreach ($this->walk($expression->base, $context) as $parent) {
                $container = $parent->node->root();
                foreach ($container->content as $i => $child) {
                    if (NodeKind::Mapping === $container->kind && 0 === $i % 2) {
                        continue;
                    }

                    $out[] = new Candidate($child, $parent, null, $parent->documentIndex, $parent->fileIndex, $parent->filename);
                }
            }

            return $out;
        }

        throw new EvaluationException('FakeEvaluator does not support ' . $expression::class);
    }

    private function lookup(Candidate $parent, string $key): Candidate
    {
        $content = $parent->node->root()->content;
        $counter = \count($content);
        for ($i = 0; $i + 1 < $counter; $i += 2) {
            if ($content[$i]->value === $key) {
                return new Candidate($content[$i + 1], $parent, $content[$i], $parent->documentIndex, $parent->fileIndex, $parent->filename);
            }
        }

        return new Candidate(\LTS\PhpXq\Yaml\Node::scalar('null', '!!null'), $parent, null, $parent->documentIndex, $parent->fileIndex, $parent->filename);
    }
}
