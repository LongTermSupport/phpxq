<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Runtime\Anchors;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Comments;
use LTS\PhpXq\Yq\Runtime\Detached;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Operators about the nodes themselves: `tag`, `type`, `kind`, `style`, `anchor`, `alias`, the comment
 * readers, `explode` and `sort_keys`. (Setting them is done with `X style = "..."` by the assignment
 * operators.).
 */
final class MetaCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['tag', 'type', 'kind', 'style', 'anchor', 'alias', 'head_comment', 'headComment', 'line_comment', 'lineComment', 'foot_comment', 'footComment', 'explode', 'sort_keys'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        if ('explode' === $call->name || 'sort_keys' === $call->name) {
            return $this->mutate($call, $context, $evaluator);
        }

        $out = [];
        foreach ($context->matches as $match) {
            $out[] = Cands::derive(NodeOps::str($this->read($call->name, $match)), $match);
        }

        return $out;
    }

    private function read(string $name, Candidate $match): string
    {
        $node = Cands::node($match);
        switch ($name) {
            case 'tag':
            case 'type':
                $target = NodeOps::deref($node);

                return '' === $target->tag ? NodeOps::effectiveTag($target) : $target->tag;

            case 'kind':
                return NodeOps::kindName($node);

            case 'style':
                return $this->styleName($node);

            case 'anchor':
                return $node->anchor;

            case 'alias':
                return NodeKindEnum::Alias === $node->kind ? $node->value : '';

            case 'head_comment':
            case 'headComment':
                return Comments::read($this->comment($match, 'head'));

            case 'line_comment':
            case 'lineComment':
                return Comments::read($this->comment($match, 'line'));

            default:
                return Comments::read($this->comment($match, 'foot'));
        }
    }

    /**
     * @param 'head'|'line'|'foot' $kind
     */
    private function comment(Candidate $match, string $kind): string
    {
        $text = Comments::get($match->node, $kind);
        if ('' === $text && $match->parent instanceof Candidate && NodeKindEnum::Document === $match->parent->node->kind) {
            return Comments::get($match->parent->node, $kind);
        }

        if ('' === $text && NodeKindEnum::Document === $match->node->kind && isset($match->node->content[0])) {
            return Comments::get($match->node->content[0], $kind);
        }

        return $text;
    }

    private function styleName(Node $node): string
    {
        if ($node->tagExplicit && NodeStyleEnum::Default === $node->style) {
            return 'tagged';
        }

        return match ($node->style) {
            NodeStyleEnum::DoubleQuoted => 'double',
            NodeStyleEnum::SingleQuoted => 'single',
            NodeStyleEnum::Literal      => 'literal',
            NodeStyleEnum::Folded       => 'folded',
            NodeStyleEnum::Flow         => 'flow',
            default                     => '',
        };
    }

    /**
     * @return list<Candidate>
     */
    private function mutate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        Args::require($call, 1);
        $fixed = $context->services->yamlFixMergeAnchorToSpec;
        foreach ($evaluator->evaluate($call->arguments[0], $context->withDontAutoCreate(true)) as $target) {
            if (Detached::is($target->node)) {
                continue;
            }

            $node = Cands::node($target);
            if ('explode' === $call->name) {
                Anchors::explode($node, $fixed);

                continue;
            }

            $node = NodeOps::deref($node);
            if (NodeKindEnum::Mapping === $node->kind) {
                $this->sortPairs($node);
            }
        }

        return $context->matches;
    }

    private function sortPairs(Node $map): void
    {
        $pairs = [];
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            $pairs[] = [$map->content[$i], $map->content[$i + 1]];
        }

        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0]->value, $b[0]->value));
        $flat = [];
        foreach ($pairs as [$key, $value]) {
            $flat[] = $key;
            $flat[] = $value;
        }

        $map->content = $flat;
    }
}
