<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Format\Format;
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
final class StringCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['upcase', 'ascii_upcase', 'downcase', 'ascii_downcase', 'trim', 'ltrimstr', 'rtrimstr', 'startswith', 'endswith', 'join', 'split', 'to_string', 'tostring', 'to_number', 'tonumber', 'to_bool'];
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
        switch ($call->name) {
            case 'to_string':
            case 'tostring':
                return [Cands::derive(self::toString($node, $context), $match)];

            case 'to_number':
            case 'tonumber':
                return [Cands::derive(self::toNumber($node), $match)];

            case 'to_bool':
                return [Cands::derive(self::toBool($node), $match)];

            case 'join':
                return [Cands::derive($this->join($call, $match, $node, $context, $evaluator), $match)];

            case 'split':
                Args::require($call, 1);
                $separator = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                if (NodeOps::isNull($node)) {
                    return [];
                }

                $parts = '' === $separator ? mb_str_split(self::text($node, $call)) : explode($separator, self::text($node, $call));

                return [Cands::derive(NodeOps::seq(array_map(NodeOps::str(...), $parts)), $match)];

            default:
                return $this->transform($call, $match, $node, $context, $evaluator);
        }
    }

    private static function text(Node $node, Call $call): string
    {
        if (NodeKind::Scalar !== $node->kind) {
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

        $text = self::text($node, $call);
        switch ($call->name) {
            case 'upcase':
            case 'ascii_upcase':
                $value = mb_strtoupper($text);
                break;

            case 'downcase':
            case 'ascii_downcase':
                $value = mb_strtolower($text);
                break;

            case 'trim':
                $value = preg_replace('/^\s+|\s+$/u', '', $text) ?? $text;
                break;

            case 'ltrimstr':
            case 'rtrimstr':
                Args::require($call, 1);
                $affix = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                if ('ltrimstr' === $call->name) {
                    $value = '' !== $affix && str_starts_with($text, $affix) ? substr($text, \strlen($affix)) : $text;
                } else {
                    $value = '' !== $affix && str_ends_with($text, $affix) ? substr($text, 0, -\strlen($affix)) : $text;
                }

                break;

            default:
                Args::require($call, 1);
                $affix = Args::string($call, 0, $context, $evaluator, $match) ?? '';
                $found = 'startswith' === $call->name ? str_starts_with($text, $affix) : str_ends_with($text, $affix);

                return [Cands::derive(NodeOps::bool($found), $match)];
        }

        $out        = $node->deepCopy();
        $out->value = $value;
        $out->anchor = '';

        return [Cands::derive($out, $match)];
    }

    private function join(Call $call, Candidate $match, Node $node, EvaluationContext $context, EvaluatorInterface $evaluator): Node
    {
        Args::require($call, 1);
        $separator = Args::string($call, 0, $context, $evaluator, $match) ?? '';
        if (NodeKind::Sequence !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot join %s, join only works on arrays', '' === $node->tag ? NodeOps::kindName($node) : $node->tag));
        }

        $parts = [];
        foreach ($node->content as $item) {
            $item = NodeOps::deref($item);
            if (NodeKind::Scalar !== $item->kind) {
                throw new EvaluationException('Cannot join a collection element');
            }

            $parts[] = NodeOps::isNull($item) ? '' : $item->value;
        }

        return NodeOps::str(implode($separator, $parts));
    }

    private static function toString(Node $node, EvaluationContext $context): Node
    {
        if (NodeKind::Scalar === $node->kind) {
            $out        = NodeOps::str($node->value);
            $out->style = NodeStyle::Default;

            return $out;
        }

        try {
            $text = $context->services->formats->encoder(Format::Yaml)->encode($node, new FormatOptions(), 0);
        } catch (FormatException $formatException) {
            throw new EvaluationException($formatException->getMessage(), 0, $formatException);
        }

        $out        = NodeOps::str(rtrim($text, "\n"));
        $out->style = NodeStyle::DoubleQuoted;

        return $out;
    }

    private static function toNumber(Node $node): Node
    {
        if (NodeKind::Scalar !== $node->kind) {
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

        return new Node(NodeKind::Scalar, Numbers::tagOf($number), NodeStyle::Default, $node->value);
    }

    private static function toBool(Node $node): Node
    {
        if (NodeKind::Scalar !== $node->kind) {
            throw new EvaluationException(\sprintf('Cannot convert %s to a boolean', NodeOps::kindName($node)));
        }

        $text = strtolower($node->value);
        if ('true' === $text || 'false' === $text) {
            return NodeOps::bool('true' === $text);
        }

        throw new EvaluationException(\sprintf('cannot convert %s to a boolean', $node->value));
    }
}
