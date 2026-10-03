<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Ast\ArrayConstruct;
use LTS\PhpXq\Jq\Ast\ArrayPattern;
use LTS\PhpXq\Jq\Ast\Assign;
use LTS\PhpXq\Jq\Ast\Binary;
use LTS\PhpXq\Jq\Ast\Bind;
use LTS\PhpXq\Jq\Ast\BreakOut;
use LTS\PhpXq\Jq\Ast\Comma;
use LTS\PhpXq\Jq\Ast\ForeachLoop;
use LTS\PhpXq\Jq\Ast\Format;
use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\FuncDefScope;
use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\IfThenElse;
use LTS\PhpXq\Jq\Ast\Index;
use LTS\PhpXq\Jq\Ast\Iterate;
use LTS\PhpXq\Jq\Ast\Label;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Location;
use LTS\PhpXq\Jq\Ast\Negate;
use LTS\PhpXq\Jq\Ast\Node;
use LTS\PhpXq\Jq\Ast\NumberLiteral;
use LTS\PhpXq\Jq\Ast\ObjectConstruct;
use LTS\PhpXq\Jq\Ast\ObjectPattern;
use LTS\PhpXq\Jq\Ast\Pattern;
use LTS\PhpXq\Jq\Ast\Pipe;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Ast\Reduce;
use LTS\PhpXq\Jq\Ast\Slice;
use LTS\PhpXq\Jq\Ast\StringInterpolation;
use LTS\PhpXq\Jq\Ast\TryCatch;
use LTS\PhpXq\Jq\Ast\Variable;
use LTS\PhpXq\Jq\Ast\VariablePattern;

/**
 * Renders an AST as a compact S-expression so tests can assert tree shapes as strings.
 *
 * @internal
 */
final class ParserAstDumper
{
    public static function dump(Node $node): string
    {
        return match (true) {
            $node instanceof Identity            => '.',
            $node instanceof Literal             => json_encode($node->value, \JSON_THROW_ON_ERROR),
            $node instanceof NumberLiteral       => $node->text,
            $node instanceof Format              => '@' . $node->name,
            $node instanceof StringInterpolation => self::interpolation($node),
            $node instanceof Variable            => '$' . $node->name,
            $node instanceof Location            => '$__loc__:' . $node->line,
            $node instanceof Index               => '(idx ' . self::dump($node->target) . ' ' . self::dump($node->index) . ')',
            $node instanceof Slice               => '(slice ' . self::dump($node->target) . ' ' . self::opt($node->from) . ' ' . self::opt($node->to) . ')',
            $node instanceof Iterate             => '(iter ' . self::dump($node->target) . ')',
            $node instanceof TryCatch            => $node->handler instanceof \LTS\PhpXq\Jq\Ast\Node
                ? '(try ' . self::dump($node->body) . ' ' . self::dump($node->handler) . ')'
                : '(try ' . self::dump($node->body) . ')',
            $node instanceof ArrayConstruct      => '[' . self::opt($node->body, '') . ']',
            $node instanceof ObjectConstruct     => self::object($node),
            $node instanceof Pipe                => '(| ' . self::dump($node->left) . ' ' . self::dump($node->right) . ')',
            $node instanceof Comma               => '(, ' . self::dump($node->left) . ' ' . self::dump($node->right) . ')',
            $node instanceof Negate              => '(neg ' . self::dump($node->operand) . ')',
            $node instanceof Binary              => '(' . $node->op->value . ' ' . self::dump($node->left) . ' ' . self::dump($node->right) . ')',
            $node instanceof Assign              => '(' . $node->op->value . ' ' . self::dump($node->left) . ' ' . self::dump($node->right) . ')',
            $node instanceof IfThenElse          => '(if ' . self::dump($node->condition) . ' ' . self::dump($node->then) . ' ' . self::opt($node->else) . ')',
            $node instanceof Bind                => '(as ' . self::dump($node->source) . ' (' . implode(' ?// ', array_map(self::pattern(...), $node->patterns)) . ') ' . self::dump($node->body) . ')',
            $node instanceof Reduce              => '(reduce ' . self::dump($node->source) . ' ' . self::pattern($node->pattern) . ' ' . self::dump($node->init) . ' ' . self::dump($node->update) . ')',
            $node instanceof ForeachLoop         => '(foreach ' . self::dump($node->source) . ' ' . self::pattern($node->pattern) . ' ' . self::dump($node->init) . ' ' . self::dump($node->update) . ' ' . self::opt($node->extract) . ')',
            $node instanceof Label               => '(label ' . $node->name . ' ' . self::dump($node->body) . ')',
            $node instanceof BreakOut            => '(break ' . $node->label . ')',
            $node instanceof FuncDefScope        => '(def ' . self::def($node->def) . ' ' . self::dump($node->rest) . ')',
            $node instanceof FunctionCall        => [] === $node->args
                ? $node->name
                : '(' . $node->name . ' ' . implode(' ', array_map(self::dump(...), $node->args)) . ')',
            default                              => throw new InvalidArgumentException('unknown node ' . $node::class),
        };
    }

    /**
     * The body of a program with its top-level defs folded back into nested `(def ...)` forms.
     */
    public static function program(Program $program): string
    {
        $text = $program->body instanceof \LTS\PhpXq\Jq\Ast\Node ? self::dump($program->body) : '_';
        foreach (array_reverse($program->defs) as $def) {
            $text = '(def ' . self::def($def) . ' ' . $text . ')';
        }

        return $text;
    }

    public static function def(FuncDef $def): string
    {
        return $def->name . '(' . implode(' ', $def->params) . ') ' . self::dump($def->body);
    }

    public static function pattern(Pattern $pattern): string
    {
        if ($pattern instanceof VariablePattern) {
            return '$' . $pattern->name;
        }

        if ($pattern instanceof ArrayPattern) {
            return '[' . implode(' ', array_map(self::pattern(...), $pattern->elements)) . ']';
        }

        if ($pattern instanceof ObjectPattern) {
            $parts = [];
            foreach ($pattern->entries as $entry) {
                $parts[] = '(' . (null === $entry->variable ? '_' : '$' . $entry->variable)
                    . ' ' . self::opt($entry->key)
                    . ' ' . (null === $entry->value ? '_' : self::pattern($entry->value)) . ')';
            }

            return '{' . implode(' ', $parts) . '}';
        }

        throw new InvalidArgumentException('unknown pattern ' . $pattern::class);
    }

    private static function opt(?Node $node, string $none = '_'): string
    {
        return $node instanceof \LTS\PhpXq\Jq\Ast\Node ? self::dump($node) : $none;
    }

    private static function interpolation(StringInterpolation $node): string
    {
        $parts = [];
        foreach ($node->parts as $part) {
            $parts[] = \is_string($part) ? json_encode($part, \JSON_THROW_ON_ERROR) : self::dump($part);
        }

        return '(interp ' . ($node->format ?? '-') . ' ' . implode(' ', $parts) . ')';
    }

    private static function object(ObjectConstruct $node): string
    {
        $parts = [];
        foreach ($node->entries as $entry) {
            $parts[] = '(' . self::dump($entry->key) . ' ' . self::dump($entry->value) . ')';
        }

        return '{' . implode(' ', $parts) . '}';
    }
}
