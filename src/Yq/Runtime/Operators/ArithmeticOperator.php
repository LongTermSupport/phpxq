<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use DateTimeImmutable;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
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
 *
 * @internal
 */
final readonly class ArithmeticOperator implements BinaryOperatorInterface
{
    /** Go yq's cap on the result of a string repetition (10 MiB). */
    private const int MAX_REPEAT_BYTES = 10485760;

    public function operators(): array
    {
        return [
            BinaryOperatorEnum::Add,
            BinaryOperatorEnum::Subtract,
            BinaryOperatorEnum::Multiply,
            BinaryOperatorEnum::Divide,
            BinaryOperatorEnum::Modulo,
        ];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $operator  = $expression->operator;
        $modifiers = $expression->modifiers;
        $layout    = Cands::dateLayout($context);
        $replaced  = $context->replacedNode;

        return Cross::run(
            $expression->left,
            $expression->right,
            $context,
            $evaluator,
            static function (?Candidate $left, ?Candidate $right, ?Candidate $from) use ($operator, $modifiers, $layout, $replaced): ?Candidate {
                $result = self::apply($operator, $left instanceof Candidate ? $left->node : null, $right instanceof Candidate ? $right->node : null, $modifiers, $layout, $replaced);

                return $result instanceof Node ? Cands::deriveInDocument($result, $from) : null;
            },
        );
    }

    /**
     * Applies an arithmetic operator to two nodes (null: no match on that side) and returns a new node,
     * or null when neither side exists. A side that is the `$replaced` node (the one the result is about to
     * take the place of) hands over its children instead of copies, unless both sides are that node.
     *
     * @throws EvaluationException
     */
    public static function apply(BinaryOperatorEnum $operator, ?Node $left, ?Node $right, string $modifiers = '', ?string $layout = null, ?Node $replaced = null): ?Node
    {
        $l = $left instanceof Node ? NodeOps::deref(NodeOps::unwrap($left)) : null;
        $r = $right instanceof Node ? NodeOps::deref(NodeOps::unwrap($right)) : null;

        $shareLeft  = $replaced instanceof Node && $l === $replaced && $r !== $replaced;
        $shareRight = $replaced instanceof Node && $r === $replaced && $l !== $replaced;

        return match ($operator) {
            BinaryOperatorEnum::Add, BinaryOperatorEnum::AddAssign           => self::add($l, $r, $layout, $shareLeft, $shareRight),
            BinaryOperatorEnum::Subtract, BinaryOperatorEnum::SubtractAssign => self::subtract($l, $r, $layout, $shareLeft),
            BinaryOperatorEnum::Multiply, BinaryOperatorEnum::MultiplyAssign => self::multiply($l, $r, $modifiers),
            BinaryOperatorEnum::Divide, BinaryOperatorEnum::DivideAssign     => self::divide($l, $r),
            BinaryOperatorEnum::Modulo, BinaryOperatorEnum::ModuloAssign     => self::modulo($l, $r),
            default                                                          => throw new EvaluationException('Unsupported arithmetic operator ' . $operator->value),
        };
    }

    private static function own(Node $node, bool $share): Node
    {
        return $share ? $node : $node->deepCopy();
    }

    private static function add(?Node $l, ?Node $r, ?string $layout, bool $shareLeft, bool $shareRight): ?Node
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

        if (NodeKindEnum::Sequence === $l->kind) {
            return self::addToSequence($l, $r, $shareLeft, $shareRight);
        }

        if (NodeKindEnum::Mapping === $l->kind && NodeKindEnum::Mapping === $r->kind) {
            $merged = $shareLeft ? clone $l : $l->deepCopy();
            for ($i = 0, $n = \count($r->content); $i < $n; $i += 2) {
                $found = null;
                for ($j = 0, $m = \count($merged->content); $j < $m; $j += 2) {
                    if ($merged->content[$j]->value === $r->content[$i]->value) {
                        $found = $j;

                        break;
                    }
                }

                if (null === $found) {
                    $merged->content[] = self::own($r->content[$i], $shareRight);
                    $merged->content[] = self::own($r->content[$i + 1], $shareRight);
                } else {
                    array_splice($merged->content, $found + 1, 1, [self::own($r->content[$i + 1], $shareRight)]);
                }
            }

            return $merged;
        }

        if (NodeKindEnum::Scalar !== $l->kind || NodeKindEnum::Scalar !== $r->kind) {
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
            $out->style = NodeStyleEnum::Default;
        }

        return $out;
    }

    private static function addToSequence(Node $l, Node $r, bool $shareLeft, bool $shareRight): Node
    {
        $items = $shareLeft ? $l->content : array_map(static fn (Node $item): Node => $item->deepCopy(), $l->content);
        if (NodeKindEnum::Sequence === $r->kind) {
            foreach ($r->content as $item) {
                $items[] = self::own($item, $shareRight);
            }
        } else {
            $add  = clone $r; // shallow: children stay shared so `.. |= [] + .` still updates them after the parent is replaced
            $last = [] === $items ? null : $items[\count($items) - 1];
            if ($last instanceof Node && NodeKindEnum::Scalar === $last->kind && NodeKindEnum::Scalar === $add->kind && NodeStyleEnum::Default === $add->style && NodeStyleEnum::Default !== $last->style) {
                $add->style = $last->style;
            }

            $items[] = $add;
        }

        $new        = NodeOps::seq($items);
        $new->style = $l->style;
        $new->tag   = $l->tag;

        return $new;
    }

    private static function subtract(?Node $l, ?Node $r, ?string $layout, bool $shareLeft): ?Node
    {
        if (!$l instanceof Node) {
            return null;
        }

        if (!$r instanceof Node || NodeOps::isNull($r)) {
            return $l->deepCopy();
        }

        if (NodeKindEnum::Sequence === $l->kind) {
            $remove = NodeKindEnum::Sequence === $r->kind ? $r->content : [$r];
            $items  = [];
            foreach ($l->content as $item) {
                $drop = array_any($remove, static fn (Node $candidate): bool => Compare::deepEquals($item, $candidate));
                if (!$drop) {
                    $items[] = self::own($item, $shareLeft);
                }
            }

            $new        = NodeOps::seq($items);
            $new->style = $l->style;
            $new->tag   = $l->tag;

            return $new;
        }

        if (NodeKindEnum::Scalar !== $l->kind || NodeKindEnum::Scalar !== $r->kind) {
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

        if (NodeKindEnum::Scalar === $l->kind && NodeKindEnum::Scalar === $r->kind) {
            $ln = Numbers::of($l);
            $rn = Numbers::of($r);
            if (null !== $ln && null !== $rn) {
                return self::numberNode($l, $ln * $rn);
            }

            if (null !== $rn && CoreSchema::TAG_STR === NodeOps::effectiveTag($l)) {
                return self::repeat($l, $r, $rn, $l, $r);
            }

            if (null !== $ln && CoreSchema::TAG_STR === NodeOps::effectiveTag($r)) {
                return self::repeat($r, $l, $ln, $l, $r);
            }
        }

        $merged = $l->deepCopy();
        Merger::fromModifiers($modifiers)->merge($merged, $r);

        return $merged;
    }

    /**
     * Go yq's string repetition: an `!!int` count only, never negative, and at most {@see self::MAX_REPEAT_BYTES}.
     *
     * @throws EvaluationException
     */
    private static function repeat(Node $text, Node $count, int|float $times, Node $left, Node $right): Node
    {
        if (CoreSchema::TAG_INT !== NodeOps::effectiveTag($count)) {
            throw new EvaluationException(\sprintf('cannot multiply %s with %s', NodeOps::effectiveTag($left), NodeOps::effectiveTag($right)));
        }

        if ($times < 0) {
            throw new EvaluationException(\sprintf('cannot repeat string by a negative number (%s)', $count->value));
        }

        $bytes = \strlen($text->value);
        if (!\is_int($times) || $bytes * $times > self::MAX_REPEAT_BYTES) {
            throw new EvaluationException(\sprintf('result of repeating string (%d bytes) by %s would exceed %d bytes', $bytes, $count->value, self::MAX_REPEAT_BYTES));
        }

        $out        = NodeOps::str(str_repeat($text->value, $times));
        $out->tag   = $text->tag;
        $out->style = NodeStyleEnum::Default;

        return $out;
    }

    private static function divide(?Node $l, ?Node $r): ?Node
    {
        if (!$l instanceof Node || !$r instanceof Node) {
            return $l?->deepCopy() ?? $r?->deepCopy();
        }

        if (NodeKindEnum::Scalar === $l->kind && NodeKindEnum::Scalar === $r->kind) {
            $ln = Numbers::of($l);
            $rn = Numbers::of($r);
            if (null !== $ln && null !== $rn) {
                $out      = NodeOps::float(fdiv((float)$ln, (float)$rn));
                $out->tag = self::keepCustomTag($l, $out->tag);

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

        return self::numberNode($l, fmod((float)$ln, (float)$rn), true);
    }

    private static function numberNode(Node $like, int|float $value, bool $forceFloat = false): Node
    {
        $out      = $forceFloat || \is_float($value) ? NodeOps::float((float)$value) : NodeOps::int($value);
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

        $moved      = GoTime::addNanos($time, $sign * $nanos);
        $out        = $l->deepCopy();
        $out->value = GoTime::format($moved, $layout ?? GoTime::RFC3339);

        return $out;
    }
}
