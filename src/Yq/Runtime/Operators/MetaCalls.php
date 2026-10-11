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
use LTS\PhpXq\Yq\Runtime\CommentKindEnum;
use LTS\PhpXq\Yq\Runtime\Comments;
use LTS\PhpXq\Yq\Runtime\Detached;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Operators about the nodes themselves: `tag`, `type`, `kind`, `style`, `anchor`, `alias`, the comment
 * readers, `explode` and `sort_keys`. (Setting them is done with `X style = "..."` by the assignment
 * operators.).
 *
 * @internal
 */
final readonly class MetaCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return BuiltinNameEnum::values(
            BuiltinNameEnum::Tag,
            BuiltinNameEnum::Type,
            BuiltinNameEnum::Kind,
            BuiltinNameEnum::Style,
            BuiltinNameEnum::Anchor,
            BuiltinNameEnum::Alias,
            BuiltinNameEnum::HeadComment,
            BuiltinNameEnum::HeadCommentCamel,
            BuiltinNameEnum::LineComment,
            BuiltinNameEnum::LineCommentCamel,
            BuiltinNameEnum::FootComment,
            BuiltinNameEnum::FootCommentCamel,
            BuiltinNameEnum::Explode,
            BuiltinNameEnum::SortKeys,
        );
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        if (BuiltinNameEnum::Explode->value === $call->name || BuiltinNameEnum::SortKeys->value === $call->name) {
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
        switch (BuiltinNameEnum::tryFrom($name)) {
            case BuiltinNameEnum::Tag:
            case BuiltinNameEnum::Type:
                if (NodeKindEnum::Alias === $node->kind) {
                    return '';
                }

                $target = NodeOps::deref($node);

                return '' === $target->tag ? NodeOps::effectiveTag($target) : $target->tag;

            case BuiltinNameEnum::Kind:
                return NodeOps::kindName($node);

            case BuiltinNameEnum::Style:
                return $this->styleName($node);

            case BuiltinNameEnum::Anchor:
                return $node->anchor;

            case BuiltinNameEnum::Alias:
                return NodeKindEnum::Alias === $node->kind ? $node->value : '';

            case BuiltinNameEnum::HeadComment:
            case BuiltinNameEnum::HeadCommentCamel:
                return Comments::read($this->comment($match, CommentKindEnum::Head));

            case BuiltinNameEnum::LineComment:
            case BuiltinNameEnum::LineCommentCamel:
                return Comments::read($this->comment($match, CommentKindEnum::Line));

            default:
                return Comments::read($this->comment($match, CommentKindEnum::Foot));
        }
    }

    private function comment(Candidate $match, CommentKindEnum $kind): string
    {
        $document = null;
        if (NodeKindEnum::Document === $match->node->kind) {
            $document = $match->node;
        } elseif ($match->parent instanceof Candidate && NodeKindEnum::Document === $match->parent->node->kind) {
            $document = $match->parent->node;
        }

        if (CommentKindEnum::Head === $kind && $document instanceof Node && !$document->commentsCleared && '' !== $document->leadingContent) {
            return Comments::fromLeadingContent($document->leadingContent);
        }

        $text = Comments::get($match->node, $kind);
        if ('' !== $text || !$document instanceof Node) {
            return $text;
        }

        $text = Comments::get($document, $kind);
        if ('' === $text && $document === $match->node && isset($document->content[0])) {
            return Comments::get($document->content[0], $kind);
        }

        return $text;
    }

    private function styleName(Node $node): string
    {
        if ($node->tagExplicit && NodeStyleEnum::Default === $node->style) {
            return StyleNameEnum::Tagged->value;
        }

        $name = StyleNameEnum::fromNodeStyle($node->style);

        return $name instanceof StyleNameEnum ? $name->value : '';
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
            if (BuiltinNameEnum::Explode->value === $call->name) {
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
