<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Parser;

use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
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
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionToken;
use LTS\PhpXq\Yq\Expression\ExpressionTokenKindEnum;

/**
 * Precedence-climbing parser over a token list from ExpressionLexer.
 *
 * Binding power, loosest first (left associative unless noted): `|` (1), `,` (2), assignment `= |= += -=
 * *= /= %= =c` (3, right associative), `//` (4), `or` (5), `and` (6), comparison (7), `+ -` (8), `* / %` (9).
 * Operands are postfix chains (`.a.b[0]?`) followed by any number of juxtaposed words: `X word` is
 * `Pipe(X, Call(word))` and binds tighter than every operator, which is how `.a style = "x"` reads as
 * `Assign(Pipe(.a, style), "x")`. `X as $v | body`, `X ref $v | body` and `X as $v ireduce (init; update)`
 * are operands too: the body runs to the end of the enclosing pipe.
 *
 * Field names after a dot are string literals (`.a.0` is the key "0"); `.[expr]` keeps the expression, so
 * `.[0]` is an integer key. Inside `{ }` values a `,` separates entries and so is not the union operator.
 *
 * Nesting is limited to {@see NestingLimit::MAX_DEPTH} levels. `$depth` counts the expressions the parser is
 * inside: every bracket, operand of a higher-binding operator, interpolation or nested construct it recurses
 * into is one level below its parent, and the outermost expression is level 0. A chain the parser builds in a
 * loop (`|`, `,`, `+`, `.a.b`, `.[0]`, juxtaposed words) is not nesting: union operands are joined into a
 * balanced tree, and the other chains are left-deep, as deep as they are long. `$reach` is the deepest level
 * any node of the tree under construction has reached, chains included (a wrap pushes everything parsed so far
 * one level down), and it is limited to {@see NestingLimit::MAX_TREE_DEPTH}.
 *
 * @internal
 */
final class PrattParser
{
    private const int OUTSIDE_THE_EXPRESSION = -1;

    private const string TOO_DEEP = 'Bad expression, nested deeper than %d levels';

    private const string TOO_LONG = 'Bad expression, tree deeper than %d levels, counting chained operations';

    private const string MISSING_PAREN = 'Bad expression, could not find matching `)`';

    private const string MISSING_BRACKET = 'Bad expression, could not find matching `]`';

    private const string GENERIC = 'Bad expression, please check expression syntax';

    private const array RESERVED = [
        'and'  => true, 'or' => true, 'as' => true, 'ireduce' => true, 'then' => true, 'elif' => true,
        'else' => true, 'end' => true, 'if' => true, 'reduce' => true, 'true' => true, 'false' => true,
        'null' => true, '~' => true,
    ];

    private int $pos = 0;

    private bool $union = true;

    private int $reach;

    /**
     * @param list<ExpressionToken> $tokens always ends with an EndOfInput token
     * @param int                   $depth  the level enclosing the expression: an interpolation's is its string's
     */
    public function __construct(
        private readonly array $tokens,
        private int $depth = self::OUTSIDE_THE_EXPRESSION,
    ) {
        $this->reach = $depth;
    }

    /**
     * @throws ExpressionSyntaxException
     */
    public function parseAll(): ExpressionNodeInterface
    {
        if (ExpressionTokenKindEnum::EndOfInput === $this->tokens[0]->kind) {
            return new Identity();
        }

        $node = $this->parseExpr(1);
        if (ExpressionTokenKindEnum::EndOfInput !== $this->tokens[$this->pos]->kind) {
            throw $this->fail(self::GENERIC);
        }

        return $node;
    }

    private function parseExpr(int $minBp): ExpressionNodeInterface
    {
        $outer      = $this->depth;
        $outerReach = $this->reach;
        $this->nest();
        $this->reach = $this->depth;
        $left        = $this->parseOperand();
        while (true) {
            $info = $this->binaryInfo($this->tokens[$this->pos]);
            if (null === $info || $info[0] < $minBp) {
                break;
            }

            ++$this->pos;
            $leftReach = $this->reach;
            $rightBp   = $info[1] ? $info[0] : $info[0] + 1;
            $left      = BinaryOperatorEnum::Union === $info[2]
                ? $this->parseUnionChain($left, $rightBp)
                : new Binary($info[2], $left, $this->parseExpr($rightBp), $info[3]);
            $this->deepen($leftReach + 1);
        }

        $this->depth = $outer;
        $this->reach = max($outerReach, $this->reach);

        return $left;
    }

    /**
     * After the first `,` of a chain: every operand of the chain, joined into a balanced tree of unions. The
     * union is associative, so the results keep their order however the operands are grouped, and a chain of
     * any length stays a tree of logarithmic depth.
     */
    private function parseUnionChain(ExpressionNodeInterface $first, int $rightBp): ExpressionNodeInterface
    {
        $operands = [$first, $this->parseExpr($rightBp)];
        while (ExpressionTokenKindEnum::Operator === $this->tokens[$this->pos]->kind && ',' === $this->tokens[$this->pos]->text) {
            ++$this->pos;
            $operands[] = $this->parseExpr($rightBp);
        }

        // join neighbours pairwise, round after round, until one tree is left
        while (\count($operands) > 1) {
            $joined = [];
            $count  = \count($operands);
            for ($i = 0; $i + 1 < $count; $i += 2) {
                $joined[] = new Binary(BinaryOperatorEnum::Union, $operands[$i], $operands[$i + 1]);
            }

            if (1 === $count % 2) {
                $joined[] = $operands[$count - 1];
            }

            $operands = $joined;
        }

        return $operands[0];
    }

    /**
     * One level deeper for the expression about to be parsed; the caller restores the level it saved once that
     * expression is complete.
     *
     * @throws ExpressionSyntaxException past {@see NestingLimit::MAX_DEPTH} levels
     */
    private function nest(): void
    {
        ++$this->depth;
        if ($this->depth > NestingLimit::MAX_DEPTH) {
            throw $this->fail(\sprintf(self::TOO_DEEP, NestingLimit::MAX_DEPTH));
        }

        $this->deepen($this->depth);
    }

    /**
     * Records that the tree under construction reaches the given level.
     *
     * @throws ExpressionSyntaxException past {@see NestingLimit::MAX_TREE_DEPTH} levels
     */
    private function deepen(int $level): void
    {
        $this->reach = max($this->reach, $level);
        if ($this->reach > NestingLimit::MAX_TREE_DEPTH) {
            throw $this->fail(\sprintf(self::TOO_LONG, NestingLimit::MAX_TREE_DEPTH));
        }
    }

    /**
     * @return array{int, bool, BinaryOperatorEnum, string}|null binding power, right associative, operator, modifiers
     */
    private function binaryInfo(ExpressionToken $token): ?array
    {
        if (ExpressionTokenKindEnum::Word === $token->kind) {
            return match ($token->text) {
                'or'    => [1, false, BinaryOperatorEnum::Or, ''],
                'and'   => [2, false, BinaryOperatorEnum::And, ''],
                default => null,
            };
        }

        if (ExpressionTokenKindEnum::Operator !== $token->kind) {
            return null;
        }

        $text = $token->text;

        return match ($text) {
            '|'     => [3, false, BinaryOperatorEnum::Pipe, ''],
            ','     => $this->union ? [4, false, BinaryOperatorEnum::Union, ''] : null,
            '='     => [5, true, BinaryOperatorEnum::Assign, ''],
            '=c'    => [5, true, BinaryOperatorEnum::Assign, 'c'],
            '|='    => [5, true, BinaryOperatorEnum::Update, ''],
            '+='    => [5, true, BinaryOperatorEnum::AddAssign, ''],
            '-='    => [5, true, BinaryOperatorEnum::SubtractAssign, ''],
            '/='    => [5, true, BinaryOperatorEnum::DivideAssign, ''],
            '%='    => [5, true, BinaryOperatorEnum::ModuloAssign, ''],
            '//'    => [6, false, BinaryOperatorEnum::Alternative, ''],
            '=='    => [7, false, BinaryOperatorEnum::Equal, ''],
            '!='    => [7, false, BinaryOperatorEnum::NotEqual, ''],
            '<'     => [7, false, BinaryOperatorEnum::Less, ''],
            '<='    => [7, false, BinaryOperatorEnum::LessOrEqual, ''],
            '>'     => [7, false, BinaryOperatorEnum::Greater, ''],
            '>='    => [7, false, BinaryOperatorEnum::GreaterOrEqual, ''],
            '+'     => [8, false, BinaryOperatorEnum::Add, ''],
            '-'     => [8, false, BinaryOperatorEnum::Subtract, ''],
            '/'     => [9, false, BinaryOperatorEnum::Divide, ''],
            '%'     => [9, false, BinaryOperatorEnum::Modulo, ''],
            default => $this->multiplyInfo($text),
        };
    }

    /**
     * @return array{int, bool, BinaryOperatorEnum, string}|null
     */
    private function multiplyInfo(string $text): ?array
    {
        if ('*' !== $text[0]) {
            return null;
        }

        if (isset($text[1]) && '=' === $text[1]) {
            return [5, true, BinaryOperatorEnum::MultiplyAssign, substr($text, 2)];
        }

        return [9, false, BinaryOperatorEnum::Multiply, substr($text, 1)];
    }

    private function parseOperand(bool $allowBind = true): ExpressionNodeInterface
    {
        $outerReach  = $this->reach;
        $this->reach = $this->depth;
        $node        = $this->parsePrimary();
        while (true) {
            $node  = $this->parsePostfix($node);
            $token = $this->tokens[$this->pos];
            if (ExpressionTokenKindEnum::Word !== $token->kind) {
                break;
            }

            $word = $token->text;
            if ($allowBind && ('as' === $word || ('ref' === $word && ExpressionTokenKindEnum::Variable === $this->tokens[$this->pos + 1]->kind))) {
                $nodeReach = $this->reach;
                $node      = $this->parseBind($node, $word);
                $this->deepen($nodeReach + 1);

                break;
            }

            if (isset(self::RESERVED[$word])) {
                break;
            }

            ++$this->pos;
            $nodeReach = $this->reach;
            $node      = new Binary(BinaryOperatorEnum::Pipe, $node, $this->finishCall($word));
            $this->deepen($nodeReach + 1);
        }

        $this->reach = max($outerReach, $this->reach);

        return $node;
    }

    private function parseBind(ExpressionNodeInterface $source, string $word): ExpressionNodeInterface
    {
        ++$this->pos;
        $variable = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::Variable !== $variable->kind) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
        $next = $this->tokens[$this->pos];
        if ('as' === $word && ExpressionTokenKindEnum::Word === $next->kind && 'ireduce' === $next->text) {
            ++$this->pos;
            $this->expect(ExpressionTokenKindEnum::LeftParen, 'Bad expression, could not find matching `(`');
            $initial = $this->parseFull();
            $this->expect(ExpressionTokenKindEnum::Semicolon, self::GENERIC);
            $update = $this->parseFull();
            $this->expect(ExpressionTokenKindEnum::RightParen, self::MISSING_PAREN);

            return new Reduce($source, $variable->text, $initial, $update);
        }

        if (ExpressionTokenKindEnum::Operator !== $next->kind || '|' !== $next->text) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;

        return new Bind($source, $variable->text, $this->parseExpr(1), 'ref' === $word);
    }

    private function parsePrimary(): ExpressionNodeInterface
    {
        $token = $this->tokens[$this->pos];
        switch ($token->kind) {
            case ExpressionTokenKindEnum::Number:
                ++$this->pos;

                return new Literal(Node::scalar($token->text));
            case ExpressionTokenKindEnum::String:
                ++$this->pos;

                return $this->stringNode($token);
            case ExpressionTokenKindEnum::Variable:
                ++$this->pos;

                return new VariableRef($token->text);
            case ExpressionTokenKindEnum::Dot:
                return $this->parseDot($token);
            case ExpressionTokenKindEnum::DotDot:
            case ExpressionTokenKindEnum::DotDotDot:
                ++$this->pos;
                $node = new RecursiveDescent(new Identity(), ExpressionTokenKindEnum::DotDotDot === $token->kind);
                $this->optional();

                return $node;
            case ExpressionTokenKindEnum::LeftParen:
                ++$this->pos;
                $inner = $this->parseFull();
                $this->expect(ExpressionTokenKindEnum::RightParen, self::MISSING_PAREN);

                return $inner;
            case ExpressionTokenKindEnum::LeftBracket:
                return $this->parseCollect();
            case ExpressionTokenKindEnum::LeftBrace:
                return $this->parseObject();
            case ExpressionTokenKindEnum::Word:
                return $this->parseWord($token);
            case ExpressionTokenKindEnum::Operator:
                if ('-' === $token->text && ExpressionTokenKindEnum::Number === $this->tokens[$this->pos + 1]->kind) {
                    $this->pos += 2;

                    return new Literal(Node::scalar('-' . $this->tokens[$this->pos - 1]->text));
                }

                break;
            default:
                break;
        }

        throw $this->fail(self::GENERIC);
    }

    private function parseDot(ExpressionToken $dot): ExpressionNodeInterface
    {
        $next = $this->tokens[$this->pos + 1];
        if ($next->offset === $dot->offset + 1) {
            if (ExpressionTokenKindEnum::Word === $next->kind) {
                $this->pos += 2;

                return $this->field(new Identity(), $this->literalString($next->text));
            }

            if (ExpressionTokenKindEnum::String === $next->kind) {
                $this->pos += 2;

                return $this->field(new Identity(), $this->stringNode($next));
            }
        }

        ++$this->pos;

        return new Identity();
    }

    private function parseWord(ExpressionToken $token): ExpressionNodeInterface
    {
        $word = $token->text;
        switch ($word) {
            case 'true':
            case 'false':
                ++$this->pos;

                return new Literal(Node::scalar($word, '!!bool'));
            case 'null':
            case '~':
                ++$this->pos;

                return new Literal(Node::scalar($word, '!!null'));
            case 'if':
                ++$this->pos;
                $conditional = $this->parseIfBody();
                $this->expectWord('end');

                return $conditional;
            case 'reduce':
                return $this->parsePrefixReduce();
            default:
                if (isset(self::RESERVED[$word])) {
                    throw $this->fail(self::GENERIC);
                }

                ++$this->pos;

                return $this->finishCall($word);
        }
    }

    private function parseIfBody(): Conditional
    {
        $condition = $this->parseFull();
        $this->expectWord('then');
        $then  = $this->parseFull();
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::Word === $token->kind) {
            if ('elif' === $token->text) {
                ++$this->pos;
                $outer = $this->depth;
                $this->nest();
                $elif        = new Conditional($condition, $then, $this->parseIfBody());
                $this->depth = $outer;

                return $elif;
            }

            if ('else' === $token->text) {
                ++$this->pos;

                return new Conditional($condition, $then, $this->parseFull());
            }
        }

        return new Conditional($condition, $then);
    }

    private function parsePrefixReduce(): ExpressionNodeInterface
    {
        ++$this->pos;
        $outer = $this->depth;
        $this->nest();
        $source      = $this->parseOperand(false);
        $this->depth = $outer;
        $this->expectWord('as');
        $variable = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::Variable !== $variable->kind) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
        $this->expect(ExpressionTokenKindEnum::LeftParen, 'Bad expression, could not find matching `(`');
        $initial = $this->parseFull();
        $this->expect(ExpressionTokenKindEnum::Semicolon, self::GENERIC);
        $update = $this->parseFull();
        $this->expect(ExpressionTokenKindEnum::RightParen, self::MISSING_PAREN);

        return new Reduce($source, $variable->text, $initial, $update);
    }

    private function finishCall(string $name): Call
    {
        if (ExpressionTokenKindEnum::LeftParen !== $this->tokens[$this->pos]->kind) {
            return new Call($name);
        }

        ++$this->pos;
        if (ExpressionTokenKindEnum::RightParen === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new Call($name);
        }

        $arguments = [$this->parseFull()];
        while (ExpressionTokenKindEnum::Semicolon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $arguments[] = $this->parseFull();
        }

        $this->expect(ExpressionTokenKindEnum::RightParen, self::MISSING_PAREN);

        return new Call($name, $arguments);
    }

    private function parseCollect(): ExpressionNodeInterface
    {
        ++$this->pos;
        if (ExpressionTokenKindEnum::RightBracket === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new Collect();
        }

        $inner = $this->parseFull();
        $this->expect(ExpressionTokenKindEnum::RightBracket, self::MISSING_BRACKET);

        return new Collect($inner);
    }

    private function parseObject(): ExpressionNodeInterface
    {
        ++$this->pos;
        if (ExpressionTokenKindEnum::RightBrace === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new ObjectConstruct();
        }

        $entries = [];
        while (true) {
            $entries[] = $this->parseObjectEntry();
            $token     = $this->tokens[$this->pos];
            if (ExpressionTokenKindEnum::Operator === $token->kind && ',' === $token->text) {
                ++$this->pos;

                continue;
            }

            $this->expect(ExpressionTokenKindEnum::RightBrace, 'Bad expression, could not find matching `}`');

            return new ObjectConstruct($entries);
        }
    }

    private function parseObjectEntry(): ObjectEntry
    {
        $token = $this->tokens[$this->pos];
        $next  = $this->tokens[$this->pos + 1] ?? $token;
        $key   = null;
        if (ExpressionTokenKindEnum::Word === $token->kind && !isset(self::RESERVED[$token->text]) && $this->endsEntryKey($next)) {
            $key = $this->literalString($token->text);
            ++$this->pos;
        } elseif (ExpressionTokenKindEnum::String === $token->kind && !$token->raw && $this->endsEntryKey($next)) {
            $key = $this->literalString($token->text);
            ++$this->pos;
        }

        if (!$key instanceof Literal) {
            $outer = $this->depth;
            $this->nest();
            $key         = $this->parseOperand(false);
            $this->depth = $outer;
            $this->expect(ExpressionTokenKindEnum::Colon, 'Bad expression, could not find matching `}`');

            return new ObjectEntry($key, $this->parseNoUnion());
        }

        if (ExpressionTokenKindEnum::Colon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new ObjectEntry($key, $this->parseNoUnion());
        }

        return new ObjectEntry($key, new Field(new Identity(), $key));
    }

    private function endsEntryKey(ExpressionToken $next): bool
    {
        return ExpressionTokenKindEnum::Colon      === $next->kind
            || ExpressionTokenKindEnum::RightBrace === $next->kind
            || (ExpressionTokenKindEnum::Operator === $next->kind && ',' === $next->text);
    }

    private function parsePostfix(ExpressionNodeInterface $node): ExpressionNodeInterface
    {
        while (true) {
            $nodeReach = $this->reach;
            $next      = $this->postfixStep($node);
            if (!$next instanceof ExpressionNodeInterface) {
                return $node;
            }

            $node = $next;
            $this->deepen($nodeReach + 1);
        }
    }

    /**
     * The node wrapped in the postfix step at the current token, or null when no step follows.
     */
    private function postfixStep(ExpressionNodeInterface $node): ?ExpressionNodeInterface
    {
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::LeftBracket === $token->kind) {
            return $this->parseBracket($node);
        }

        if (ExpressionTokenKindEnum::Dot !== $token->kind) {
            return null;
        }

        $next = $this->tokens[$this->pos + 1];
        if ($next->offset !== $token->offset + 1) {
            return null;
        }

        if (ExpressionTokenKindEnum::Word === $next->kind) {
            $this->pos += 2;

            return $this->field($node, $this->literalString($next->text));
        }

        if (ExpressionTokenKindEnum::String === $next->kind) {
            $this->pos += 2;

            return $this->field($node, $this->stringNode($next));
        }

        if (ExpressionTokenKindEnum::LeftBracket === $next->kind) {
            ++$this->pos;

            return $this->parseBracket($node);
        }

        return null;
    }

    private function parseBracket(ExpressionNodeInterface $base): ExpressionNodeInterface
    {
        ++$this->pos;
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::RightBracket === $token->kind) {
            ++$this->pos;

            // iterating never fails, so a trailing `?` changes nothing and is only consumed
            $this->optional();

            return new Iterate($base);
        }

        $from = ExpressionTokenKindEnum::Colon === $token->kind ? null : $this->parseFull();
        if (ExpressionTokenKindEnum::Colon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $to = ExpressionTokenKindEnum::RightBracket === $this->tokens[$this->pos]->kind ? null : $this->parseFull();
            $this->expect(ExpressionTokenKindEnum::RightBracket, self::MISSING_BRACKET);

            return new Slice($base, $from, $to, $this->optional());
        }

        $this->expect(ExpressionTokenKindEnum::RightBracket, self::MISSING_BRACKET);

        return $this->field($base, $from ?? throw $this->fail(self::GENERIC));
    }

    private function field(ExpressionNodeInterface $base, ExpressionNodeInterface $key): Field
    {
        return new Field($base, $key, $this->optional());
    }

    private function optional(): bool
    {
        $optional = false;
        while (ExpressionTokenKindEnum::Question === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $optional = true;
        }

        return $optional;
    }

    private function parseFull(): ExpressionNodeInterface
    {
        $saved       = $this->union;
        $this->union = true;
        try {
            return $this->parseExpr(1);
        } finally {
            $this->union = $saved;
        }
    }

    private function parseNoUnion(): ExpressionNodeInterface
    {
        $saved       = $this->union;
        $this->union = false;
        try {
            return $this->parseExpr(1);
        } finally {
            $this->union = $saved;
        }
    }

    private function literalString(string $text): Literal
    {
        return new Literal(Node::scalar($text, '!!str'));
    }

    private function stringNode(ExpressionToken $token): ExpressionNodeInterface
    {
        if (!$token->raw) {
            return $this->literalString($token->text);
        }

        $parts    = [];
        $hasNodes = false;
        $text     = '';
        foreach ($token->parts as $part) {
            if (\is_string($part)) {
                $parts[] = $part;
                $text   .= $part;

                continue;
            }

            $hasNodes = true;
            $nested   = new self($part, $this->depth);
            $parts[]  = $nested->parseAll();
            $this->deepen($nested->reach);
        }

        return $hasNodes ? new Interpolation($parts) : $this->literalString($text);
    }

    private function expect(ExpressionTokenKindEnum $kind, string $message): void
    {
        if ($kind !== $this->tokens[$this->pos]->kind) {
            throw $this->fail($message);
        }

        ++$this->pos;
    }

    private function expectWord(string $word): void
    {
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKindEnum::Word !== $token->kind || $word !== $token->text) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
    }

    private function fail(string $message): ExpressionSyntaxException
    {
        return new ExpressionSyntaxException($message, $this->tokens[$this->pos]->offset);
    }
}
