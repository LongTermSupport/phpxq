<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\Bind;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\Ast\Collect;
use LTS\PhpXq\Yq\Expression\Ast\Conditional;
use LTS\PhpXq\Yq\Expression\Ast\Field;
use LTS\PhpXq\Yq\Expression\Ast\Identity;
use LTS\PhpXq\Yq\Expression\Ast\Interpolation;
use LTS\PhpXq\Yq\Expression\Ast\Iterate;
use LTS\PhpXq\Yq\Expression\Ast\Literal;
use LTS\PhpXq\Yq\Expression\Ast\ObjectConstruct;
use LTS\PhpXq\Yq\Expression\Ast\ObjectEntry;
use LTS\PhpXq\Yq\Expression\Ast\RecursiveDescent;
use LTS\PhpXq\Yq\Expression\Ast\Reduce;
use LTS\PhpXq\Yq\Expression\Ast\Slice;
use LTS\PhpXq\Yq\Expression\Ast\VariableRef;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use RuntimeException;

/**
 * Renders an AST as a compact S-expression so tests can assert whole trees as one string.
 */
final class AstDumper
{
    public static function dump(ExpressionNodeInterface $node): string
    {
        return match (true) {
            $node instanceof Identity         => '.',
            $node instanceof Literal          => $node->value->tag . ':' . $node->value->value,
            $node instanceof VariableRef      => '$' . $node->name,
            $node instanceof Field            => '(field' . ($node->optional ? '?' : '') . ' ' . self::dump($node->base) . ' ' . self::dump($node->key) . ')',
            $node instanceof Slice            => '(slice' . ($node->optional ? '?' : '') . ' ' . self::dump($node->base) . ' ' . self::optional($node->from) . ' ' . self::optional($node->to) . ')',
            $node instanceof Iterate          => '(iter' . ($node->optional ? '?' : '') . ' ' . self::dump($node->base) . ')',
            $node instanceof RecursiveDescent => '(' . ($node->includeKeys ? 'recall' : 'rec') . ' ' . self::dump($node->base) . ')',
            $node instanceof Binary           => '(' . $node->operator->value . ('' === $node->modifiers ? '' : '/' . $node->modifiers) . ' ' . self::dump($node->left) . ' ' . self::dump($node->right) . ')',
            $node instanceof Call             => '(call ' . $node->name . self::spaced(...$node->arguments) . ')',
            $node instanceof Collect          => '(collect ' . self::optional($node->inner) . ')',
            $node instanceof ObjectConstruct  => '(obj' . self::entries(...$node->entries) . ')',
            $node instanceof Conditional      => '(if ' . self::dump($node->condition) . ' ' . self::dump($node->then) . ' ' . self::optional($node->otherwise) . ')',
            $node instanceof Bind             => '(' . ($node->reference ? 'ref' : 'as') . ' $' . $node->name . ' ' . self::dump($node->source) . ' ' . self::dump($node->body) . ')',
            $node instanceof Reduce           => '(reduce $' . $node->name . ' ' . self::dump($node->source) . ' ' . self::dump($node->initial) . ' ' . self::dump($node->update) . ')',
            $node instanceof Interpolation    => '(interp' . self::parts(...$node->parts) . ')',
            default                           => throw new RuntimeException('unknown node ' . $node::class),
        };
    }

    private static function spaced(ExpressionNodeInterface ...$nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $text .= ' ' . self::dump($node);
        }

        return $text;
    }

    private static function entries(ObjectEntry ...$entries): string
    {
        $text = '';
        foreach ($entries as $entry) {
            $text .= ' [' . self::dump($entry->key) . ' ' . self::dump($entry->value) . ']';
        }

        return $text;
    }

    private static function parts(ExpressionNodeInterface|string ...$parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            $text .= ' ' . (\is_string($part) ? "'" . $part . "'" : self::dump($part));
        }

        return $text;
    }

    private static function optional(?ExpressionNodeInterface $node): string
    {
        return $node instanceof ExpressionNodeInterface ? self::dump($node) : '_';
    }
}
