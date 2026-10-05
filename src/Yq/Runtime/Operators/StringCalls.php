<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Numbers;

/**
 * String operators: `upcase`, `downcase`, `trim`, `ltrimstr`, `rtrimstr`, `startswith`, `endswith`,
 * `join`, `split`, `to_string`, `to_number`, `to_bool`.
 */
final readonly class StringCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return BuiltinNameEnum::values(
            BuiltinNameEnum::Upcase,
            BuiltinNameEnum::AsciiUpcase,
            BuiltinNameEnum::Downcase,
            BuiltinNameEnum::AsciiDowncase,
            BuiltinNameEnum::Trim,
            BuiltinNameEnum::Ltrimstr,
            BuiltinNameEnum::Rtrimstr,
            BuiltinNameEnum::Startswith,
            BuiltinNameEnum::Endswith,
            BuiltinNameEnum::Join,
            BuiltinNameEnum::Split,
            BuiltinNameEnum::ToString,
            BuiltinNameEnum::ToStringFlat,
            BuiltinNameEnum::ToNumber,
            BuiltinNameEnum::ToNumberFlat,
            BuiltinNameEnum::ToBool,
        );
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $out = [];
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
        $node = NodeOps::deref(Cands::node($match));
        switch (BuiltinNameEnum::tryFrom($call->name)) {
            case BuiltinNameEnum::ToString:
            case BuiltinNameEnum::ToStringFlat:
                return [Cands::derive($this->toString($node, $context), $match)];

            case BuiltinNameEnum::ToNumber:
            case BuiltinNameEnum::ToNumberFlat:
                return [Cands::derive($this->toNumber($node), $match)];

            case BuiltinNameEnum::ToBool:
                return [Cands::derive($this->toBool($node), $match)];

            case BuiltinNameEnum::Join:
                return [Cands::derive($this->join($call, $match, $node, $context, $evaluator), $match)];

            case BuiltinNameEnum::Split:
                Args::require($call, 1);
                $separator = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                if (NodeOps::isNull($node)) {
                    return [];
                }

                $parts = '' === $separator ? mb_str_split($this->text($node, $call)) : explode($separator, $this->text($node, $call));

                return [Cands::derive(NodeOps::seq(array_map(NodeOps::str(...), $parts)), $match)];

            default:
                return $this->transform($call, $match, $node, $context, $evaluator);
        }
    }

    private function text(Node $node, Call $call): string
    {
        if (NodeKindEnum::Scalar !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot apply %s to %s', $call->name, '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
        }

        return $node->value;
    }

    /**
     * @return list<Candidate>
     */
    private function transform(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        if (NodeOps::isNull($node)) {
            return [$match];
        }

        $text = $this->text($node, $call);
        switch (BuiltinNameEnum::tryFrom($call->name)) {
            case BuiltinNameEnum::Upcase:
            case BuiltinNameEnum::AsciiUpcase:
                $value = mb_strtoupper($text);
                break;

            case BuiltinNameEnum::Downcase:
            case BuiltinNameEnum::AsciiDowncase:
                $value = mb_strtolower($text);
                break;

            case BuiltinNameEnum::Trim:
                $value = preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
                break;

            case BuiltinNameEnum::Ltrimstr:
            case BuiltinNameEnum::Rtrimstr:
                Args::require($call, 1);
                $affix = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                if (BuiltinNameEnum::Ltrimstr->value === $call->name) {
                    $value = '' !== $affix && str_starts_with($text, $affix) ? substr($text, \strlen($affix)) : $text;
                } else {
                    $value = '' !== $affix && str_ends_with($text, $affix) ? substr($text, 0, -\strlen($affix)) : $text;
                }

                break;

            default:
                Args::require($call, 1);
                $affix = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
                $found = BuiltinNameEnum::Startswith->value === $call->name ? str_starts_with($text, $affix) : str_ends_with($text, $affix);

                return [Cands::derive(NodeOps::bool($found), $match)];
        }

        $out         = $node->deepCopy();
        $out->value  = $value;
        $out->anchor = '';

        return [Cands::derive($out, $match)];
    }

    private function join(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): Node
    {
        Args::require($call, 1);
        $separator = Args::stringOrEmpty($call, 0, $context, $evaluator, $match);
        if (NodeKindEnum::Sequence !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot join %s, join only works on arrays', '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
        }

        $parts = [];
        foreach ($node->content as $item) {
            $item = NodeOps::deref($item);
            if (NodeKindEnum::Scalar !== $item->kind) {
                throw new EvaluationException('Cannot join a collection element');
            }

            $parts[] = NodeOps::isNull($item) ? '' : $item->value;
        }

        return NodeOps::str(implode($separator, $parts));
    }

    private function toString(Node $node, EvaluationContext $context): Node
    {
        if (NodeKindEnum::Scalar === $node->kind) {
            $out        = NodeOps::str($node->value);
            $out->style = NodeStyleEnum::Default;

            return $out;
        }

        try {
            $text = $context->services->formats->encoder(FormatEnum::Yaml)->encode($node, new FormatOptions(), 0);
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }

        $out        = NodeOps::str(rtrim($text, "\n"));
        $out->style = NodeStyleEnum::DoubleQuoted;

        return $out;
    }

    private function toNumber(Node $node): Node
    {
        if (NodeKindEnum::Scalar !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot convert %s to a number', NodeOps::kindName($node)));
        }

        $tag = NodeOps::effectiveTag($node);
        if (CoreSchema::TAG_INT === $tag || CoreSchema::TAG_FLOAT === $tag) {
            return $node->deepCopy();
        }

        $number = Numbers::parse($node->value);
        if (null === $number) {
            throw new EvaluationException(\sprintf('cannot convert %s to a number', $node->value));
        }

        return new Node(NodeKindEnum::Scalar, Numbers::tagOf($number), NodeStyleEnum::Default, $node->value);
    }

    private function toBool(Node $node): Node
    {
        if (NodeKindEnum::Scalar !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot convert %s to a boolean', NodeOps::kindName($node)));
        }

        $text = strtolower($node->value);
        if (NodeOps::TRUE_TEXT === $text || NodeOps::FALSE_TEXT === $text) {
            return NodeOps::bool(NodeOps::TRUE_TEXT === $text);
        }

        throw new EvaluationException(\sprintf('cannot convert %s to a boolean', $node->value));
    }
}
