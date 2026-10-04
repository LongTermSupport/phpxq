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
 */
final class AssignOperator implements BinaryOperatorInterface
{
    private const array PROPERTY_SETTERS = [
        'style'        => 'style',
        'tag'          => 'tag',
        'anchor'       => 'anchor',
        'alias'        => 'alias',
        'comments'     => 'comments',
        'head_comment' => 'head',
        'headComment'  => 'head',
        'line_comment' => 'line',
        'lineComment'  => 'line',
        'foot_comment' => 'foot',
        'footComment'  => 'foot',
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
        $setter = self::propertySetter($expression->left);
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
                    self::replace($target, $source, 'c' === $expression->modifiers, $adopt);
                }

                break;

            case BinaryOperatorEnum::Update:
                foreach ($targets as $target) {
                    $values = $evaluator->evaluate($expression->right, $read->withMatches([$target]));
                    if ([] !== $values) {
                        self::replace($target, $values[0]->node, 'c' === $expression->modifiers, !$values[0]->parent instanceof Candidate);
                    }
                }

                break;

            default:
                $values = $evaluator->evaluate($expression->right, $read);
                $value  = $values[0] ?? null;
                foreach ($targets as $target) {
                    $result = ArithmeticOperator::apply($expression->operator, $target->node, $value?->node, $expression->modifiers, $layout);
                    if ($result instanceof Node) {
                        self::replace($target, $result, str_contains($expression->modifiers, 'c'), true);
                    }
                }

                break;
        }

        return $context->matches;
    }

    private static function replace(Candidate $target, Node $source, bool $clobberTags, bool $adopt): void
    {
        Detached::attach($target);
        NodeOps::updateFrom(Cands::node($target), $source, $clobberTags, $adopt);
    }

    /**
     * @return array{ExpressionNodeInterface, string}|null the property target expression and the property to set
     */
    private static function propertySetter(ExpressionNodeInterface $left): ?array
    {
        if ($left instanceof Binary && BinaryOperatorEnum::Pipe === $left->operator && $left->right instanceof Call && [] === $left->right->arguments && isset(self::PROPERTY_SETTERS[$left->right->name])) {
            return [$left->left, self::PROPERTY_SETTERS[$left->right->name]];
        }

        return null;
    }

    /**
     * @return list<Candidate>
     */
    private function setProperty(Binary $expression, ExpressionNodeInterface $targetExpression, string $property, EvaluationContext $context, EvaluatorInterface $evaluator): array
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
                $values = $evaluator->evaluate($expression->right, $read->withMatches([$target]));
                $source = $values[0]->node ?? null;
            }

            if (!$source instanceof Node) {
                continue;
            }

            $value = NodeKindEnum::Scalar === NodeOps::deref($source)->kind ? NodeOps::deref($source)->value : '';
            Detached::attach($target);
            self::apply($target, $property, $value);
        }

        return $context->matches;
    }

    private static function apply(Candidate $target, string $property, string $value): void
    {
        $node = $target->node;
        switch ($property) {
            case 'style':
                self::setStyle($node->root(), $value);

                return;

            case 'tag':
                $node->tag = $value;

                return;

            case 'anchor':
                $node->anchor = $value;

                return;

            case 'alias':
                self::setAlias($target, $value);

                return;

            default:
                $kind = match ($property) {
                    'head'  => 'head',
                    'foot'  => 'foot',
                    'line'  => 'line',
                    default => 'all',
                };
                Comments::set($node, $kind, $value);
                if ('head' === $kind || 'all' === $kind) {
                    // A document's slurped leading content is its head comment: setting one replaces it.
                    $parentNode = $target->parent instanceof Candidate ? $target->parent->node : null;
                    $parentDoc  = $parentNode instanceof \LTS\PhpXq\Yaml\Node && NodeKindEnum::Document === $parentNode->kind ? $parentNode : null;
                    $document   = NodeKindEnum::Document                         === $node->kind ? $node : $parentDoc;
                    if ($document instanceof Node && '' !== $document->leadingContent) {
                        $document->leadingContent = '' === $value ? '' : Comments::write($value) . "\n";
                        $node->headComment        = '';
                    }
                }

                if ('' === $value && Cands::isRoot($target) && $target->parent instanceof Candidate) {
                    Comments::set($target->parent->node, $kind, '');
                    if ('head' === $kind || 'all' === $kind) {
                        $target->parent->node->commentsCleared = true;
                    }
                }

                return;
        }
    }

    private static function setStyle(Node $node, string $style): void
    {
        $node->tagExplicit = 'tagged'              === $style;
        $collection        = NodeKindEnum::Mapping === $node->kind || NodeKindEnum::Sequence === $node->kind;
        $new               = match ($style) {
            'double'  => NodeStyleEnum::DoubleQuoted,
            'single'  => NodeStyleEnum::SingleQuoted,
            'literal' => NodeStyleEnum::Literal,
            'folded'  => NodeStyleEnum::Folded,
            'flow'    => NodeStyleEnum::Flow,
            default   => NodeStyleEnum::Default,
        };
        if ($collection && NodeStyleEnum::Flow !== $new) {
            $new = NodeStyleEnum::Default;
        }

        $node->style = $new;
    }

    private static function setAlias(Candidate $target, string $name): void
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
