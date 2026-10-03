<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Parser;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;
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
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionNode;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionToken;
use LTS\PhpXq\Yq\Expression\ExpressionTokenKind;

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
 * @internal
 */
final class PrattParser
{
    private const string GENERIC = 'Bad expression, please check expression syntax';

    private const array RESERVED = [
        'and'  => true, 'or' => true, 'as' => true, 'ireduce' => true, 'then' => true, 'elif' => true,
        'else' => true, 'end' => true, 'if' => true, 'reduce' => true, 'true' => true, 'false' => true,
        'null' => true, '~' => true,
    ];

    private int $pos = 0;

    private bool $union = true;

    /**
     * @param list<ExpressionToken> $tokens     always ends with an EndOfInput token
     * @param int                   $baseOffset added to error offsets so a nested expression reports absolute positions
     */
    public function __construct(
        private readonly array $tokens,
        private readonly int $baseOffset = 0,
    ) {
    }

    /**
     * @throws ExpressionSyntaxException
     */
    public function parseAll(): ExpressionNode
    {
        if (ExpressionTokenKind::EndOfInput === $this->tokens[0]->kind) {
            return new Identity();
        }

        $node = $this->parseExpr(1);
        if (ExpressionTokenKind::EndOfInput !== $this->tokens[$this->pos]->kind) {
            throw $this->fail(self::GENERIC);
        }

        return $node;
    }

    private function parseExpr(int $minBp): ExpressionNode
    {
        $left = $this->parseOperand();
        while (true) {
            $info = $this->binaryInfo($this->tokens[$this->pos]);
            if (null === $info || $info[0] < $minBp) {
                return $left;
            }

            ++$this->pos;
            $right = $this->parseExpr($info[1] ? $info[0] : $info[0] + 1);
            $left  = new Binary($info[2], $left, $right, $info[3]);
        }
    }

    /**
     * @return array{int, bool, BinaryOperator, string}|null binding power, right associative, operator, modifiers
     */
    private function binaryInfo(ExpressionToken $token): ?array
    {
        if (ExpressionTokenKind::Word === $token->kind) {
            return match ($token->text) {
                'or'    => [5, false, BinaryOperator::Or, ''],
                'and'   => [6, false, BinaryOperator::And, ''],
                default => null,
            };
        }

        if (ExpressionTokenKind::Operator !== $token->kind) {
            return null;
        }

        $text = $token->text;

        return match ($text) {
            '|'     => [1, false, BinaryOperator::Pipe, ''],
            ','     => $this->union ? [2, false, BinaryOperator::Union, ''] : null,
            '='     => [3, true, BinaryOperator::Assign, ''],
            '=c'    => [3, true, BinaryOperator::Assign, 'c'],
            '|='    => [3, true, BinaryOperator::Update, ''],
            '+='    => [3, true, BinaryOperator::AddAssign, ''],
            '-='    => [3, true, BinaryOperator::SubtractAssign, ''],
            '/='    => [3, true, BinaryOperator::DivideAssign, ''],
            '%='    => [3, true, BinaryOperator::ModuloAssign, ''],
            '//'    => [4, false, BinaryOperator::Alternative, ''],
            '=='    => [7, false, BinaryOperator::Equal, ''],
            '!='    => [7, false, BinaryOperator::NotEqual, ''],
            '<'     => [7, false, BinaryOperator::Less, ''],
            '<='    => [7, false, BinaryOperator::LessOrEqual, ''],
            '>'     => [7, false, BinaryOperator::Greater, ''],
            '>='    => [7, false, BinaryOperator::GreaterOrEqual, ''],
            '+'     => [8, false, BinaryOperator::Add, ''],
            '-'     => [8, false, BinaryOperator::Subtract, ''],
            '/'     => [9, false, BinaryOperator::Divide, ''],
            '%'     => [9, false, BinaryOperator::Modulo, ''],
            default => $this->multiplyInfo($text),
        };
    }

    /**
     * @return array{int, bool, BinaryOperator, string}|null
     */
    private function multiplyInfo(string $text): ?array
    {
        if ('*' !== $text[0]) {
            return null;
        }

        if (isset($text[1]) && '=' === $text[1]) {
            return [3, true, BinaryOperator::MultiplyAssign, substr($text, 2)];
        }

        return [9, false, BinaryOperator::Multiply, substr($text, 1)];
    }

    private function parseOperand(bool $allowBind = true): ExpressionNode
    {
        $node = $this->parsePrimary();
        while (true) {
            $node  = $this->parsePostfix($node);
            $token = $this->tokens[$this->pos];
            if (ExpressionTokenKind::Word !== $token->kind) {
                return $node;
            }

            $word = $token->text;
            if ($allowBind && ('as' === $word || ('ref' === $word && ExpressionTokenKind::Variable === $this->tokens[$this->pos + 1]->kind))) {
                return $this->parseBind($node, $word);
            }

            if (isset(self::RESERVED[$word])) {
                return $node;
            }

            ++$this->pos;
            $node = new Binary(BinaryOperator::Pipe, $node, $this->finishCall($word));
        }
    }

    private function parseBind(ExpressionNode $source, string $word): ExpressionNode
    {
        ++$this->pos;
        $variable = $this->tokens[$this->pos];
        if (ExpressionTokenKind::Variable !== $variable->kind) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
        $next = $this->tokens[$this->pos];
        if ('as' === $word && ExpressionTokenKind::Word === $next->kind && 'ireduce' === $next->text) {
            ++$this->pos;
            $this->expect(ExpressionTokenKind::LeftParen, 'Bad expression, could not find matching `(`');
            $initial = $this->parseFull();
            $this->expect(ExpressionTokenKind::Semicolon, self::GENERIC);
            $update = $this->parseFull();
            $this->expect(ExpressionTokenKind::RightParen, 'Bad expression, could not find matching `)`');

            return new Reduce($source, $variable->text, $initial, $update);
        }

        if (ExpressionTokenKind::Operator !== $next->kind || '|' !== $next->text) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;

        return new Bind($source, $variable->text, $this->parseExpr(1), 'ref' === $word);
    }

    private function parsePrimary(): ExpressionNode
    {
        $token = $this->tokens[$this->pos];
        switch ($token->kind) {
            case ExpressionTokenKind::Number:
                ++$this->pos;

                return new Literal(Node::scalar($token->text));
            case ExpressionTokenKind::String:
                ++$this->pos;

                return $this->stringNode($token);
            case ExpressionTokenKind::Variable:
                ++$this->pos;

                return new VariableRef($token->text);
            case ExpressionTokenKind::Dot:
                return $this->parseDot($token);
            case ExpressionTokenKind::DotDot:
            case ExpressionTokenKind::DotDotDot:
                ++$this->pos;
                $node = new RecursiveDescent(new Identity(), ExpressionTokenKind::DotDotDot === $token->kind);
                $this->optional();

                return $node;
            case ExpressionTokenKind::LeftParen:
                ++$this->pos;
                $inner = $this->parseFull();
                $this->expect(ExpressionTokenKind::RightParen, 'Bad expression, could not find matching `)`');

                return $inner;
            case ExpressionTokenKind::LeftBracket:
                return $this->parseCollect();
            case ExpressionTokenKind::LeftBrace:
                return $this->parseObject();
            case ExpressionTokenKind::Word:
                return $this->parseWord($token);
            case ExpressionTokenKind::Operator:
                if ('-' === $token->text && ExpressionTokenKind::Number === $this->tokens[$this->pos + 1]->kind) {
                    $this->pos += 2;

                    return new Literal(Node::scalar('-' . $this->tokens[$this->pos - 1]->text));
                }

                break;
            default:
                break;
        }

        throw $this->fail(self::GENERIC);
    }

    private function parseDot(ExpressionToken $dot): ExpressionNode
    {
        $next = $this->tokens[$this->pos + 1];
        if ($next->offset === $dot->offset + 1) {
            if (ExpressionTokenKind::Word === $next->kind) {
                $this->pos += 2;

                return $this->field(new Identity(), $this->literalString($next->text));
            }

            if (ExpressionTokenKind::String === $next->kind) {
                $this->pos += 2;

                return $this->field(new Identity(), $this->stringNode($next));
            }
        }

        ++$this->pos;

        return new Identity();
    }

    private function parseWord(ExpressionToken $token): ExpressionNode
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
        if (ExpressionTokenKind::Word === $token->kind) {
            if ('elif' === $token->text) {
                ++$this->pos;

                return new Conditional($condition, $then, $this->parseIfBody());
            }

            if ('else' === $token->text) {
                ++$this->pos;

                return new Conditional($condition, $then, $this->parseFull());
            }
        }

        return new Conditional($condition, $then);
    }

    private function parsePrefixReduce(): ExpressionNode
    {
        ++$this->pos;
        $source = $this->parseOperand(false);
        $this->expectWord('as');
        $variable = $this->tokens[$this->pos];
        if (ExpressionTokenKind::Variable !== $variable->kind) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
        $this->expect(ExpressionTokenKind::LeftParen, 'Bad expression, could not find matching `(`');
        $initial = $this->parseFull();
        $this->expect(ExpressionTokenKind::Semicolon, self::GENERIC);
        $update = $this->parseFull();
        $this->expect(ExpressionTokenKind::RightParen, 'Bad expression, could not find matching `)`');

        return new Reduce($source, $variable->text, $initial, $update);
    }

    private function finishCall(string $name): Call
    {
        if (ExpressionTokenKind::LeftParen !== $this->tokens[$this->pos]->kind) {
            return new Call($name);
        }

        ++$this->pos;
        if (ExpressionTokenKind::RightParen === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new Call($name);
        }

        $arguments = [$this->parseFull()];
        while (ExpressionTokenKind::Semicolon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $arguments[] = $this->parseFull();
        }

        $this->expect(ExpressionTokenKind::RightParen, 'Bad expression, could not find matching `)`');

        return new Call($name, $arguments);
    }

    private function parseCollect(): ExpressionNode
    {
        ++$this->pos;
        if (ExpressionTokenKind::RightBracket === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new Collect();
        }

        $inner = $this->parseFull();
        $this->expect(ExpressionTokenKind::RightBracket, 'Bad expression, could not find matching `]`');

        return new Collect($inner);
    }

    private function parseObject(): ExpressionNode
    {
        ++$this->pos;
        if (ExpressionTokenKind::RightBrace === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new ObjectConstruct();
        }

        $entries = [];
        while (true) {
            $entries[] = $this->parseObjectEntry();
            $token     = $this->tokens[$this->pos];
            if (ExpressionTokenKind::Operator === $token->kind && ',' === $token->text) {
                ++$this->pos;

                continue;
            }

            $this->expect(ExpressionTokenKind::RightBrace, 'Bad expression, could not find matching `}`');

            return new ObjectConstruct($entries);
        }
    }

    private function parseObjectEntry(): ObjectEntry
    {
        $token = $this->tokens[$this->pos];
        $next  = $this->tokens[$this->pos + 1];
        $key   = null;
        if (ExpressionTokenKind::Word === $token->kind && !isset(self::RESERVED[$token->text]) && $this->endsEntryKey($next)) {
            $key = $this->literalString($token->text);
            ++$this->pos;
        } elseif (ExpressionTokenKind::String === $token->kind && !$token->raw && $this->endsEntryKey($next)) {
            $key = $this->literalString($token->text);
            ++$this->pos;
        }

        if (!$key instanceof Literal) {
            $key = $this->parseOperand(false);
            $this->expect(ExpressionTokenKind::Colon, 'Bad expression, could not find matching `}`');

            return new ObjectEntry($key, $this->parseNoUnion());
        }

        if (ExpressionTokenKind::Colon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;

            return new ObjectEntry($key, $this->parseNoUnion());
        }

        return new ObjectEntry($key, new Field(new Identity(), $key));
    }

    private function endsEntryKey(ExpressionToken $next): bool
    {
        return ExpressionTokenKind::Colon      === $next->kind
            || ExpressionTokenKind::RightBrace === $next->kind
            || (ExpressionTokenKind::Operator === $next->kind && ',' === $next->text);
    }

    private function parsePostfix(ExpressionNode $node): ExpressionNode
    {
        while (true) {
            $token = $this->tokens[$this->pos];
            if (ExpressionTokenKind::LeftBracket === $token->kind) {
                $node = $this->parseBracket($node);

                continue;
            }

            if (ExpressionTokenKind::Dot !== $token->kind) {
                return $node;
            }

            $next = $this->tokens[$this->pos + 1];
            if ($next->offset !== $token->offset + 1) {
                return $node;
            }

            if (ExpressionTokenKind::Word === $next->kind) {
                $this->pos += 2;
                $node       = $this->field($node, $this->literalString($next->text));
            } elseif (ExpressionTokenKind::String === $next->kind) {
                $this->pos += 2;
                $node       = $this->field($node, $this->stringNode($next));
            } elseif (ExpressionTokenKind::LeftBracket === $next->kind) {
                ++$this->pos;
                $node = $this->parseBracket($node);
            } else {
                return $node;
            }
        }
    }

    private function parseBracket(ExpressionNode $base): ExpressionNode
    {
        ++$this->pos;
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKind::RightBracket === $token->kind) {
            ++$this->pos;

            return new Iterate($base, $this->optional());
        }

        $from = ExpressionTokenKind::Colon === $token->kind ? null : $this->parseFull();
        if (ExpressionTokenKind::Colon === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $to = ExpressionTokenKind::RightBracket === $this->tokens[$this->pos]->kind ? null : $this->parseFull();
            $this->expect(ExpressionTokenKind::RightBracket, 'Bad expression, could not find matching `]`');

            return new Slice($base, $from, $to, $this->optional());
        }

        $this->expect(ExpressionTokenKind::RightBracket, 'Bad expression, could not find matching `]`');

        return $this->field($base, $from ?? throw $this->fail(self::GENERIC));
    }

    private function field(ExpressionNode $base, ExpressionNode $key): Field
    {
        return new Field($base, $key, $this->optional());
    }

    private function optional(): bool
    {
        $optional = false;
        while (ExpressionTokenKind::Question === $this->tokens[$this->pos]->kind) {
            ++$this->pos;
            $optional = true;
        }

        return $optional;
    }

    private function parseFull(): ExpressionNode
    {
        $saved       = $this->union;
        $this->union = true;
        try {
            return $this->parseExpr(1);
        } finally {
            $this->union = $saved;
        }
    }

    private function parseNoUnion(): ExpressionNode
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

    private function stringNode(ExpressionToken $token): ExpressionNode
    {
        if (!$token->raw) {
            return $this->literalString($token->text);
        }

        $parts      = [];
        $hasNodes   = false;
        $text       = '';
        foreach (StringLiteral::split($token->text) as $part) {
            if (\is_string($part)) {
                $parts[] = $part;
                $text   .= $part;

                continue;
            }

            $hasNodes = true;
            $parts[]  = $this->subParse($part[0], $this->baseOffset + $token->offset + 1 + $part[1]);
        }

        return $hasNodes ? new Interpolation($parts) : $this->literalString($text);
    }

    private function subParse(string $source, int $base): ExpressionNode
    {
        try {
            $tokens = new ExpressionLexer()->tokenize($source);
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            throw new ExpressionSyntaxException($expressionSyntaxException->getMessage(), $expressionSyntaxException->offset + $base);
        }

        return new self($tokens, $base)->parseAll();
    }

    private function expect(ExpressionTokenKind $kind, string $message): void
    {
        if ($kind !== $this->tokens[$this->pos]->kind) {
            throw $this->fail($message);
        }

        ++$this->pos;
    }

    private function expectWord(string $word): void
    {
        $token = $this->tokens[$this->pos];
        if (ExpressionTokenKind::Word !== $token->kind || $word !== $token->text) {
            throw $this->fail(self::GENERIC);
        }

        ++$this->pos;
    }

    private function fail(string $message): ExpressionSyntaxException
    {
        return new ExpressionSyntaxException($message, $this->baseOffset + $this->tokens[$this->pos]->offset);
    }
}
