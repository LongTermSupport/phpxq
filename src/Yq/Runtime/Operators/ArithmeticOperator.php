<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use DateTimeImmutable;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\Cross;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\GoTime;
use LTS\PhpXq\Yq\Runtime\Merger;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Numbers;

/**
 * `+ - * / %`: addition and concatenation, subtraction and set difference, multiplication, merge and
 * string repetition, division and string splitting, modulo; with dates plus or minus a duration. The
 * same arithmetic backs the compound assignments (`+=`, `-=`, ...).
 */
final class ArithmeticOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [
            BinaryOperator::Add,
            BinaryOperator::Subtract,
            BinaryOperator::Multiply,
            BinaryOperator::Divide,
            BinaryOperator::Modulo,
        ];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $operator  = $expression->operator;
        $modifiers = $expression->modifiers;
        $layout    = Cands::dateLayout($context);

        return Cross::run(
            $expression->left,
            $expression->right,
            $context,
            $evaluator,
            static function (?Candidate $left, ?Candidate $right, ?Candidate $from) use ($operator, $modifiers, $layout): ?Candidate {
                $result = self::apply($operator, $left instanceof Candidate ? $left->node : null, $right instanceof Candidate ? $right->node : null, $modifiers, $layout);

                return $result instanceof Node ? Cands::derive($result, $from) : null;
            },
        );
    }

    /**
     * Applies an arithmetic operator to two nodes (null: no match on that side) and returns a new node,
     * or null when neither side exists.
     *
     * @throws EvaluationException
     */
    public static function apply(BinaryOperator $operator, ?Node $left, ?Node $right, string $modifiers = '', ?string $layout = null): ?Node
    {
        $l = $left instanceof Node ? NodeOps::deref(NodeOps::unwrap($left)) : null;
        $r = $right instanceof Node ? NodeOps::deref(NodeOps::unwrap($right)) : null;

        return match ($operator) {
            BinaryOperator::Add, BinaryOperator::AddAssign           => self::add($l, $r, $layout),
            BinaryOperator::Subtract, BinaryOperator::SubtractAssign => self::subtract($l, $r, $layout),
            BinaryOperator::Multiply, BinaryOperator::MultiplyAssign => self::multiply($l, $r, $modifiers),
            BinaryOperator::Divide, BinaryOperator::DivideAssign     => self::divide($l, $r),
            BinaryOperator::Modulo, BinaryOperator::ModuloAssign     => self::modulo($l, $r),
            default                                                  => throw new EvaluationException('Unsupported arithmetic operator ' . $operator->value),
        };
    }

    private static function add(?Node $l, ?Node $r, ?string $layout): ?Node
    {
        if (!$l instanceof Node) {
            return $r?->deepCopy();
        }

        if (!$r instanceof Node || NodeOps::isNull($r)) {
            return $l->deepCopy();
        }

        if (NodeOps::isNull($l)) {
            return $r->deepCopy();
        }

        if (NodeKind::Sequence === $l->kind) {
            return self::addToSequence($l, $r);
        }

        if (NodeKind::Mapping === $l->kind && NodeKind::Mapping === $r->kind) {
            $merged = $l->deepCopy();
            for ($i = 0, $n = \count($r->content); $i < $n; $i += 2) {
                $found = null;
                for ($j = 0, $m = \count($merged->content); $j < $m; $j += 2) {
                    if ($merged->content[$j]->value === $r->content[$i]->value) {
                        $found = $j;

                        break;
                    }
                }

                if (null === $found) {
                    $merged->content[] = $r->content[$i]->deepCopy();
                    $merged->content[] = $r->content[$i + 1]->deepCopy();
                } else {
                    $merged->content[$found + 1] = $r->content[$i + 1]->deepCopy();
                }
            }

            return $merged;
        }

        if (NodeKind::Scalar !== $l->kind || NodeKind::Scalar !== $r->kind) {
            throw new EvaluationException(\sprintf('%s (%s) cannot be added to a %s (%s)', $r->tag, NodeOps::kindName($r), $l->tag, NodeOps::kindName($l)));
        }

        $date = self::dateArithmetic($l, $r, 1, $layout);
        if ($date instanceof Node) {
            return $date;
        }

        $ln = Numbers::of($l);
        $rn = Numbers::of($r);
        if (null !== $ln && null !== $rn) {
            return self::numberNode($l, $ln + $rn);
        }

        $out        = $l->deepCopy();
        $out->value = $l->value . $r->value;
        if (CoreSchema::TAG_STR !== NodeOps::effectiveTag($l)) {
            $out->tag   = CoreSchema::TAG_STR;
            $out->style = NodeStyle::Default;
        }

        return $out;
    }

    private static function addToSequence(Node $l, Node $r): Node
    {
        $items = array_map(static fn (Node $item): Node => $item->deepCopy(), $l->content);
        if (NodeKind::Sequence === $r->kind) {
            foreach ($r->content as $item) {
                $items[] = $item->deepCopy();
            }
        } else {
            $add  = $r->deepCopy();
            $last = [] === $items ? null : $items[\count($items) - 1];
            if ($last instanceof Node && NodeKind::Scalar === $last->kind && NodeKind::Scalar === $add->kind && NodeStyle::Default === $add->style && NodeStyle::Default !== $last->style) {
                $add->style = $last->style;
            }

            $items[] = $add;
        }

        $new        = NodeOps::seq($items);
        $new->style = $l->style;
        $new->tag   = $l->tag;

        return $new;
    }

    private static function subtract(?Node $l, ?Node $r, ?string $layout): ?Node
    {
        if (!$l instanceof Node) {
            return null;
        }

        if (!$r instanceof Node || NodeOps::isNull($r)) {
            return $l->deepCopy();
        }

        if (NodeKind::Sequence === $l->kind) {
            $remove = NodeKind::Sequence === $r->kind ? $r->content : [$r];
            $items  = [];
            foreach ($l->content as $item) {
                $drop = false;
                foreach ($remove as $candidate) {
                    if (Compare::deepEquals($item, $candidate)) {
                        $drop = true;

                        break;
                    }
                }

                if (!$drop) {
                    $items[] = $item->deepCopy();
                }
            }

            $new        = NodeOps::seq($items);
            $new->style = $l->style;
            $new->tag   = $l->tag;

            return $new;
        }

        if (NodeKind::Scalar !== $l->kind || NodeKind::Scalar !== $r->kind) {
            throw new EvaluationException(\sprintf('%s (%s) cannot be subtracted from %s (%s)', $r->tag, NodeOps::kindName($r), $l->tag, NodeOps::kindName($l)));
        }

        $date = self::dateArithmetic($l, $r, -1, $layout);
        if ($date instanceof Node) {
            return $date;
        }

        $ln = Numbers::of($l);
        $rn = Numbers::of($r);
        if (null !== $ln && null !== $rn) {
            return self::numberNode($l, $ln - $rn);
        }

        throw new EvaluationException(\sprintf('%s (%s) cannot be subtracted from %s (%s)', $r->tag, NodeOps::kindName($r), $l->tag, NodeOps::kindName($l)));
    }

    private static function multiply(?Node $l, ?Node $r, string $modifiers): ?Node
    {
        if (!$l instanceof Node || NodeOps::isNull($l)) {
            return $r?->deepCopy();
        }

        if (!$r instanceof Node || NodeOps::isNull($r)) {
            return $l->deepCopy();
        }

        if (NodeKind::Scalar === $l->kind && NodeKind::Scalar === $r->kind) {
            $ln = Numbers::of($l);
            $rn = Numbers::of($r);
            if (null !== $ln && null !== $rn) {
                return self::numberNode($l, $ln * $rn);
            }

            if (null !== $rn && CoreSchema::TAG_STR === NodeOps::effectiveTag($l)) {
                return self::repeat($l, $rn);
            }

            if (null !== $ln && CoreSchema::TAG_STR === NodeOps::effectiveTag($r)) {
                return self::repeat($r, $ln);
            }
        }

        $merged = $l->deepCopy();
        Merger::fromModifiers($modifiers)->merge($merged, $r);

        return $merged;
    }

    private static function repeat(Node $text, int|float $times): Node
    {
        $out        = NodeOps::str(str_repeat($text->value, max(0, (int) $times)));
        $out->tag   = $text->tag;
        $out->style = NodeStyle::Default;

        return $out;
    }

    private static function divide(?Node $l, ?Node $r): ?Node
    {
        if (!$l instanceof Node || !$r instanceof Node) {
            return $l?->deepCopy() ?? $r?->deepCopy();
        }

        if (NodeKind::Scalar === $l->kind && NodeKind::Scalar === $r->kind) {
            $ln = Numbers::of($l);
            $rn = Numbers::of($r);
            if (null !== $ln && null !== $rn) {
                $out        = NodeOps::float(fdiv((float) $ln, (float) $rn));
                $out->tag   = self::keepCustomTag($l, CoreSchema::TAG_FLOAT);

                return $out;
            }

            if (CoreSchema::TAG_STR === NodeOps::effectiveTag($l) && CoreSchema::TAG_STR === NodeOps::effectiveTag($r)) {
                $parts = '' === $r->value ? mb_str_split($l->value) : explode($r->value, $l->value);

                return NodeOps::seq(array_map(NodeOps::str(...), $parts));
            }
        }

        throw new EvaluationException(\sprintf('%s (%s) cannot be divided by %s (%s)', $l->tag, NodeOps::kindName($l), $r->tag, NodeOps::kindName($r)));
    }

    private static function modulo(?Node $l, ?Node $r): ?Node
    {
        if (!$l instanceof Node || !$r instanceof Node) {
            return $l?->deepCopy() ?? $r?->deepCopy();
        }

        $ln = Numbers::of($l);
        $rn = Numbers::of($r);
        if (null === $ln || null === $rn) {
            throw new EvaluationException(\sprintf('%s (%s) cannot be modded by %s (%s)', $l->tag, NodeOps::kindName($l), $r->tag, NodeOps::kindName($r)));
        }

        if (\is_int($ln) && \is_int($rn) && 0 !== $rn) {
            return self::numberNode($l, $ln % $rn);
        }

        return self::numberNode($l, fmod((float) $ln, (float) $rn), true);
    }

    private static function numberNode(Node $like, int|float $value, bool $forceFloat = false): Node
    {
        $out = $forceFloat || \is_float($value) ? NodeOps::float((float) $value) : NodeOps::int($value);
        if (\is_float($value) && floor($value) === $value && abs($value) < 1.0e15 && !$forceFloat && CoreSchema::TAG_INT === NodeOps::effectiveTag($like) && \is_int(Numbers::parse($like->value))) {
            $out->tag = CoreSchema::TAG_FLOAT;
        }

        $out->tag = self::keepCustomTag($like, $out->tag);

        return $out;
    }

    private static function keepCustomTag(Node $like, string $default): string
    {
        $tag = $like->tag;
        if ('' !== $tag && '!' === $tag[0] && !str_starts_with($tag, '!!')) {
            return $tag;
        }

        return $default;
    }

    private static function dateArithmetic(Node $l, Node $r, int $sign, ?string $layout): ?Node
    {
        if (CoreSchema::TAG_STR !== NodeOps::effectiveTag($r)) {
            return null;
        }

        $nanos = GoTime::parseDuration($r->value);
        if (null === $nanos) {
            return null;
        }

        $time = GoTime::tryParse($l->value, $layout);
        if (!$time instanceof DateTimeImmutable) {
            return null;
        }

        $moved = GoTime::addNanos($time, $sign * $nanos);
        $out   = $l->deepCopy();
        $out->value = GoTime::format($moved, $layout ?? GoTime::RFC3339);

        return $out;
    }
}
