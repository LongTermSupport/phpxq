<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Runtime\Anchors;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\CommentKindEnum;
use LTS\PhpXq\Yq\Runtime\Comments;
use LTS\PhpXq\Yq\Runtime\Detached;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * `=`, `|=` and the compound assignments (`+=`, `-=`, `*=`, `/=`, `%=`). The left side is evaluated against
 * the document and each match is updated in place; the result is the unchanged input. `X style = "..."`
 * (and `tag`, `anchor`, `alias`, `comments`, `head_comment`, `line_comment`, `foot_comment`) set a property
 * of the matched nodes instead of their value.
 *
 * @internal
 */
final readonly class AssignOperator implements BinaryOperatorInterface
{
    private const array PROPERTY_SETTERS = [
        'style'        => SettablePropertyEnum::Style,
        'tag'          => SettablePropertyEnum::Tag,
        'anchor'       => SettablePropertyEnum::Anchor,
        'alias'        => SettablePropertyEnum::Alias,
        'comments'     => SettablePropertyEnum::Comments,
        'head_comment' => SettablePropertyEnum::Head,
        'headComment'  => SettablePropertyEnum::Head,
        'line_comment' => SettablePropertyEnum::Line,
        'lineComment'  => SettablePropertyEnum::Line,
        'foot_comment' => SettablePropertyEnum::Foot,
        'footComment'  => SettablePropertyEnum::Foot,
    ];

    public function operators(): array
    {
        return [
            BinaryOperatorEnum::Assign,
            BinaryOperatorEnum::Update,
            BinaryOperatorEnum::AddAssign,
            BinaryOperatorEnum::SubtractAssign,
            BinaryOperatorEnum::MultiplyAssign,
            BinaryOperatorEnum::DivideAssign,
            BinaryOperatorEnum::ModuloAssign,
        ];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $setter = $this->propertySetter($expression->left);
        $write  = $context->withDontAutoCreate(false);
        if (null !== $setter) {
            [$target, $property] = $setter;

            return $this->setProperty($expression, $target, $property, $write, $evaluator);
        }

        $targets = $evaluator->evaluate($expression->left, $write);
        $read    = $context->withDontAutoCreate(true);
        $layout  = Cands::dateLayout($context);
        switch ($expression->operator) {
            case BinaryOperatorEnum::Assign:
                $values = $evaluator->evaluate($expression->right, $read);
                $first  = $values[0] ?? null;
                $source = $first instanceof Candidate ? $first->node : NodeOps::null();
                $adopt  = 1 === \count($targets) && (!$first instanceof Candidate || !$first->parent instanceof Candidate);
                foreach ($targets as $target) {
                    $this->replace($target, $source, 'c' === $expression->modifiers, $adopt);
                }

                break;

            case BinaryOperatorEnum::Update:
                foreach ($targets as $target) {
                    $values = $evaluator->evaluate($expression->right, $read->withMatches($target)->withReplacedNode(Cands::node($target)));
                    if ([] !== $values) {
                        $this->replace($target, $values[0]->node, 'c' === $expression->modifiers, !$values[0]->parent instanceof Candidate);
                    }
                }

                break;

            default:
                $values = $evaluator->evaluate($expression->right, $read);
                $value  = $values[0] ?? null;
                foreach ($targets as $target) {
                    $result = ArithmeticOperator::apply($expression->operator, $target->node, $value?->node, $expression->modifiers, $layout, Cands::node($target));
                    if ($result instanceof Node) {
                        $this->replace($target, $result, str_contains($expression->modifiers, 'c'), true);
                    }
                }

                break;
        }

        return $context->matches;
    }

    private function replace(Candidate $target, Node $source, bool $clobberTags, bool $adopt): void
    {
        Detached::attach($target);
        NodeOps::updateFrom(Cands::node($target), $source, $clobberTags, $adopt);
    }

    /**
     * @return array{ExpressionNodeInterface, SettablePropertyEnum}|null the property target expression and the property to set
     */
    private function propertySetter(ExpressionNodeInterface $left): ?array
    {
        if ($left instanceof Binary && BinaryOperatorEnum::Pipe === $left->operator && $left->right instanceof Call && [] === $left->right->arguments && isset(self::PROPERTY_SETTERS[$left->right->name])) {
            return [$left->left, self::PROPERTY_SETTERS[$left->right->name]];
        }

        return null;
    }

    /**
     * @return list<Candidate>
     */
    private function setProperty(Binary $expression, ExpressionNodeInterface $targetExpression, SettablePropertyEnum $property, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $targets = $evaluator->evaluate($targetExpression, $context);
        $read    = $context->withDontAutoCreate(true);
        $fixed   = null;
        if (BinaryOperatorEnum::Update !== $expression->operator) {
            $values = $evaluator->evaluate($expression->right, $read);
            $fixed  = $values[0]->node ?? null;
        }

        foreach ($targets as $target) {
            $source = $fixed;
            if (BinaryOperatorEnum::Update === $expression->operator) {
                $values = $evaluator->evaluate($expression->right, $read->withMatches($target));
                $source = $values[0]->node ?? null;
            }

            if (!$source instanceof Node) {
                continue;
            }

            $value = NodeKindEnum::Scalar === NodeOps::deref($source)->kind ? NodeOps::deref($source)->value : '';
            Detached::attach($target);
            $this->apply($target, $property, $value);
        }

        return $context->matches;
    }

    private function apply(Candidate $target, SettablePropertyEnum $property, string $value): void
    {
        $node = $target->node;
        switch ($property) {
            case SettablePropertyEnum::Style:
                $this->setStyle($node->root(), $value);

                return;

            case SettablePropertyEnum::Tag:
                $node->tag = $value;

                return;

            case SettablePropertyEnum::Anchor:
                $node->anchor = $value;

                return;

            case SettablePropertyEnum::Alias:
                $this->setAlias($target, $value);

                return;

            default:
                $kind = match ($property) {
                    SettablePropertyEnum::Head => CommentKindEnum::Head,
                    SettablePropertyEnum::Foot => CommentKindEnum::Foot,
                    SettablePropertyEnum::Line => CommentKindEnum::Line,
                    default                    => CommentKindEnum::All,
                };
                Comments::set($node, $kind, $value);
                if (CommentKindEnum::Head === $kind || CommentKindEnum::All === $kind) {
                    // A document's slurped leading content is its head comment: setting one replaces it.
                    $parentNode = $target->parent instanceof Candidate ? $target->parent->node : null;
                    $parentDoc  = $parentNode instanceof Node && NodeKindEnum::Document === $parentNode->kind ? $parentNode : null;
                    $document   = NodeKindEnum::Document                                === $node->kind ? $node : $parentDoc;
                    if ($document instanceof Node && '' !== $document->leadingContent) {
                        $document->leadingContent = '' === $value ? '' : Comments::write($value) . "\n";
                        $node->headComment        = '';
                    }
                }

                if ('' === $value && Cands::isRoot($target) && $target->parent instanceof Candidate) {
                    Comments::set($target->parent->node, $kind, '');
                    if (CommentKindEnum::Head === $kind || CommentKindEnum::All === $kind) {
                        $target->parent->node->commentsCleared = true;
                    }
                }

                return;
        }
    }

    private function setStyle(Node $node, string $style): void
    {
        $name              = StyleNameEnum::tryFrom($style);
        $node->tagExplicit = StyleNameEnum::Tagged === $name;
        $collection        = NodeKindEnum::Mapping === $node->kind || NodeKindEnum::Sequence === $node->kind;
        $new               = $name instanceof StyleNameEnum ? $name->nodeStyle() : NodeStyleEnum::Default;
        if ($collection && NodeStyleEnum::Flow !== $new) {
            $new = NodeStyleEnum::Default;
        }

        $node->style = $new;
    }

    private function setAlias(Candidate $target, string $name): void
    {
        if ('' === $name) {
            return;
        }

        $root   = Cands::root($target);
        $anchor = Anchors::find(Cands::node($root), $name);
        if (!$anchor instanceof Node && NodeKindEnum::Document === $root->node->kind) {
            $anchor = Anchors::find($root->node, $name);
        }

        $node              = $target->node;
        $node->kind        = NodeKindEnum::Alias;
        $node->value       = $name;
        $node->aliasTarget = $anchor;
        $node->content     = [];
        $node->style       = NodeStyleEnum::Default;
        $node->tag         = '';
        $node->anchor      = '';
        if (!$anchor instanceof Node) {
            throw new EvaluationException(\sprintf('Could not find anchor %s', $name));
        }
    }
}
