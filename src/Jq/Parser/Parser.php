<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LTS\PhpXq\Jq\Ast\ArrayConstruct;
use LTS\PhpXq\Jq\Ast\ArrayPattern;
use LTS\PhpXq\Jq\Ast\Assign;
use LTS\PhpXq\Jq\Ast\AssignOpEnum;
use LTS\PhpXq\Jq\Ast\Binary;
use LTS\PhpXq\Jq\Ast\BinaryOpEnum;
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
use LTS\PhpXq\Jq\Ast\ImportDirective;
use LTS\PhpXq\Jq\Ast\ImportKindEnum;
use LTS\PhpXq\Jq\Ast\Index;
use LTS\PhpXq\Jq\Ast\Iterate;
use LTS\PhpXq\Jq\Ast\Label;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Location;
use LTS\PhpXq\Jq\Ast\ModuleDirective;
use LTS\PhpXq\Jq\Ast\Negate;
use LTS\PhpXq\Jq\Ast\NodeInterface;
use LTS\PhpXq\Jq\Ast\NumberLiteral;
use LTS\PhpXq\Jq\Ast\ObjectConstruct;
use LTS\PhpXq\Jq\Ast\ObjectEntry;
use LTS\PhpXq\Jq\Ast\ObjectPattern;
use LTS\PhpXq\Jq\Ast\ObjectPatternEntry;
use LTS\PhpXq\Jq\Ast\PatternInterface;
use LTS\PhpXq\Jq\Ast\Pipe;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Ast\Reduce;
use LTS\PhpXq\Jq\Ast\Slice;
use LTS\PhpXq\Jq\Ast\StringInterpolation;
use LTS\PhpXq\Jq\Ast\TryCatch;
use LTS\PhpXq\Jq\Ast\Variable;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\NumberParser;

/**
 * Recursive descent / precedence climbing parser for the jq 1.8 grammar.
 *
 * Binary operator levels, lowest to highest (jq's parser.y): `|` 1 (right), `,` 2 (left), `//` 3 (right),
 * assignments 4 (non-assoc), `or` 5, `and` 6, comparisons 7 (non-assoc), `+ -` 8, `* / %` 9. Terms are
 * parsed with postfix chains; `as` bindings, `def` and `label` take the whole remaining pipe as their
 * body, as the grammar's lowest-precedence rules do.
 *
 * @api
 */
final class Parser implements ParserInterface
{
    private const string TOP_LEVEL_FILE = '<top-level>';

    private const int MAX_CLOSURES = 4095;

    /** @var array<string, int> operator token value => binary level */
    private const array LEVEL = [
        'pipe'           => 1,
        'comma'          => 2,
        'alt'            => 3,
        'assign'         => 4,
        'update-assign'  => 4,
        'plus-assign'    => 4,
        'minus-assign'   => 4,
        'star-assign'    => 4,
        'slash-assign'   => 4,
        'percent-assign' => 4,
        'alt-assign'     => 4,
        'or'             => 5,
        'and'            => 6,
        'eq'             => 7,
        'neq'            => 7,
        'lt'             => 7,
        'le'             => 7,
        'gt'             => 7,
        'ge'             => 7,
        'plus'           => 8,
        'minus'          => 8,
        'star'           => 9,
        'slash'          => 9,
        'percent'        => 9,
    ];

    private const int LEVEL_PIPE = 1;

    private const int LEVEL_COMMA = 2;

    private const int LEVEL_ALT = 3;

    private const int LEVEL_ASSIGN = 4;

    private const int LEVEL_COMPARE = 7;

    private const int LEVEL_MUL = 9;

    /** @var array<string, string> token type value => name used by jq's (bison) error messages */
    private const array TOKEN_NAMES = [
        'number'          => 'LITERAL',
        'ident'           => 'IDENT',
        'field'           => 'FIELD',
        'variable'        => 'BINDING',
        'format'          => 'FORMAT',
        'string-start'    => 'QQSTRING_START',
        'string-fragment' => 'QQSTRING_TEXT',
        'string-end'      => 'QQSTRING_END',
        'interp-start'    => 'QQSTRING_INTERP_START',
        'interp-end'      => 'QQSTRING_INTERP_END',
        'dot'             => "'.'",
        'dotdot'          => '..',
        'pipe'            => "'|'",
        'comma'           => "','",
        'colon'           => "':'",
        'semicolon'       => "';'",
        'lparen'          => "'('",
        'rparen'          => "')'",
        'lbracket'        => "'['",
        'rbracket'        => "']'",
        'lbrace'          => "'{'",
        'rbrace'          => "'}'",
        'question'        => "'?'",
        'assign'          => "'='",
        'lt'              => "'<'",
        'gt'              => "'>'",
        'plus'            => "'+'",
        'minus'           => "'-'",
        'star'            => "'*'",
        'slash'           => "'/'",
        'percent'         => "'%'",
        'eof'             => 'end of file',
    ];

    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private bool $bindingAllowed = true;

    /** Set once a syntax error has been annotated with "Possibly unterminated 'if' statement". */
    private bool $unterminated = false;

    public function __construct(private readonly LexerInterface $lexer)
    {
    }

    public function parse(string $source): Program
    {
        $this->tokens       = $this->lexer->tokenize($source);
        $this->pos          = 0;
        $this->unterminated = false;

        try {
            return $this->parseProgram();
        } finally {
            $this->tokens = [];
        }
    }

    private function parseProgram(): Program
    {
        $module = null;
        if (TokenTypeEnum::KwModule === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $metaTok = $this->current();
            $meta    = $this->parsePipe();
            $this->expect(TokenTypeEnum::Semicolon);
            $module = new ModuleDirective($this->metadata($meta, $metaTok));
        }

        $imports = [];
        while (TokenTypeEnum::KwImport === $this->tokens[$this->pos]->type || TokenTypeEnum::KwInclude === $this->tokens[$this->pos]->type) {
            $imports[] = $this->parseImport();
        }

        $defs = [];
        while (TokenTypeEnum::KwDef === $this->tokens[$this->pos]->type) {
            $defs[] = $this->parseDef();
        }

        $this->limitClosures(\count($defs));

        $body = null;
        if (TokenTypeEnum::Eof !== $this->tokens[$this->pos]->type) {
            $body = $this->parsePipe();
            $tail = $this->tokens[$this->pos];
            if (TokenTypeEnum::Eof !== $tail->type) {
                throw $this->unexpected($tail);
            }
        }

        return new Program($imports, $module, $defs, $body);
    }

    private function parseImport(): ImportDirective
    {
        $keyword = $this->advance();
        $pathTok = $this->current();
        if (TokenTypeEnum::StringStart !== $pathTok->type) {
            throw $this->unexpected($pathTok);
        }

        $path = $this->parseString(null);
        if (!$path instanceof Literal || !\is_string($path->value)) {
            throw $this->compileError('Import path must be constant', $pathTok);
        }

        $kind  = ImportKindEnum::Include;
        $alias = null;
        if (TokenTypeEnum::KwImport === $keyword->type) {
            $this->expect(TokenTypeEnum::KwAs);
            $name = $this->current();
            if (TokenTypeEnum::Variable === $name->type) {
                $kind = ImportKindEnum::Data;
            } elseif (TokenTypeEnum::Ident === $name->type) {
                $kind = ImportKindEnum::Import;
            } else {
                throw $this->unexpected($name);
            }

            $alias = $name->text;
            ++$this->pos;
        }

        $metadata = null;
        if (TokenTypeEnum::Semicolon !== $this->tokens[$this->pos]->type) {
            $metaTok  = $this->current();
            $metadata = $this->metadata($this->parsePipe(), $metaTok);
        }

        $this->expect(TokenTypeEnum::Semicolon);

        return new ImportDirective($path->value, $alias, $kind, $metadata);
    }

    /**
     * Fold a constant object expression (module and import metadata) into the value model.
     */
    private function metadata(NodeInterface $node, Token $at): JsonObject
    {
        $folded = $this->fold($node, $at);
        if (!$folded instanceof JsonObject) {
            throw $this->compileError('Module metadata must be an object', $at);
        }

        return $folded;
    }

    private function fold(NodeInterface $node, Token $at): mixed
    {
        if ($node instanceof Literal) {
            return $node->value;
        }

        if ($node instanceof NumberLiteral) {
            return NumberParser::parse($node->text);
        }

        if ($node instanceof ArrayConstruct) {
            $items = [];
            foreach ($this->flattenComma($node->body) as $item) {
                $items[] = $this->fold($item, $at);
            }

            return $items;
        }

        if ($node instanceof ObjectConstruct) {
            $members = [];
            foreach ($node->entries as $entry) {
                $key = $this->fold($entry->key, $at);
                if (!\is_string($key)) {
                    throw $this->compileError('Module metadata must be constant', $at);
                }

                $members[$key] = $this->fold($entry->value, $at);
            }

            return new JsonObject($members);
        }

        throw $this->compileError('Module metadata must be constant', $at);
    }

    /**
     * @return list<NodeInterface>
     */
    private function flattenComma(?NodeInterface $node): array
    {
        if (!$node instanceof NodeInterface) {
            return [];
        }

        if ($node instanceof Comma) {
            return [...$this->flattenComma($node->left), ...$this->flattenComma($node->right)];
        }

        return [$node];
    }

    /**
     * jq's bytecode addresses a function's own closures (parameters and local definitions) with a 12-bit index.
     */
    private function limitClosures(int $count): void
    {
        if ($count > self::MAX_CLOSURES) {
            throw new JqCompileException(\sprintf('too many function parameters or local function definitions (max %d)', self::MAX_CLOSURES));
        }
    }

    private function parseDef(): FuncDef
    {
        $def  = $this->advance();
        $name = $this->current();
        if (TokenTypeEnum::Ident !== $name->type) {
            throw $this->unexpected($name);
        }

        ++$this->pos;
        $params = [];
        if (TokenTypeEnum::LParen === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            while (true) {
                $param = $this->current();
                if (TokenTypeEnum::Variable === $param->type) {
                    $params[] = '$' . $param->text;
                } elseif (TokenTypeEnum::Ident === $param->type) {
                    $params[] = $param->text;
                } else {
                    throw $this->unexpected($param);
                }

                ++$this->pos;
                if (TokenTypeEnum::Semicolon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    continue;
                }

                $this->expect(TokenTypeEnum::RParen);

                break;
            }
        }

        $this->limitClosures(\count($params));
        $this->expect(TokenTypeEnum::Colon);
        $body = $this->parsePipe();
        $this->expect(TokenTypeEnum::Semicolon);

        return new FuncDef($name->text, $params, $body, $def->line);
    }

    private function parsePipe(): NodeInterface
    {
        $saved                = $this->bindingAllowed;
        $this->bindingAllowed = true;
        try {
            return $this->parseExpr(self::LEVEL_PIPE);
        } finally {
            $this->bindingAllowed = $saved;
        }
    }

    /**
     * The source of `reduce`/`foreach`: an expression above the comma level in which `as` ends the source
     * instead of starting a binding.
     */
    private function parseLoopSource(): NodeInterface
    {
        $saved                = $this->bindingAllowed;
        $this->bindingAllowed = false;
        try {
            return $this->parseExpr(self::LEVEL_ALT);
        } finally {
            $this->bindingAllowed = $saved;
        }
    }

    private function parseExpr(int $min): NodeInterface
    {
        $left = $this->parseUnary();
        while (true) {
            $tok = $this->tokens[$this->pos];
            if (TokenTypeEnum::KwAs === $tok->type && $this->bindingAllowed && $min <= self::LEVEL_ALT) {
                // `as` binds the whole operator expression on its left (`1 + 2 as $x | ...` binds 3).
                ++$this->pos;
                $patterns = $this->parsePatterns();
                $this->expect(TokenTypeEnum::Pipe);

                return new Bind($left, $patterns, $this->parsePipe());
            }

            $level = self::LEVEL[$tok->type->value] ?? 0;
            if (0 === $level || $level < $min) {
                return $left;
            }

            ++$this->pos;
            switch ($level) {
                case self::LEVEL_PIPE:
                    $left = new Pipe($left, $this->parseExpr(self::LEVEL_PIPE));

                    break;

                case self::LEVEL_COMMA:
                    $left = new Comma($left, $this->parseExpr(self::LEVEL_ALT));

                    break;

                case self::LEVEL_ALT:
                    $left = new Binary(BinaryOpEnum::Alt, $left, $this->parseExpr(self::LEVEL_ALT));

                    break;

                case self::LEVEL_ASSIGN:
                    $left = new Assign($this->assignOp($tok->type), $left, $this->parseExpr(self::LEVEL_ASSIGN + 1));
                    $this->rejectChain(self::LEVEL_ASSIGN);

                    break;

                case self::LEVEL_COMPARE:
                    $left = new Binary($this->binaryOp($tok->type), $left, $this->parseExpr(self::LEVEL_COMPARE + 1));
                    $this->rejectChain(self::LEVEL_COMPARE);

                    break;

                default:
                    $left = new Binary($this->binaryOp($tok->type), $left, $this->parseExpr($level + 1));
            }
        }
    }

    private function assignOp(TokenTypeEnum $type): AssignOpEnum
    {
        return match ($type) {
            TokenTypeEnum::Assign        => AssignOpEnum::Set,
            TokenTypeEnum::UpdateAssign  => AssignOpEnum::Update,
            TokenTypeEnum::PlusAssign    => AssignOpEnum::Add,
            TokenTypeEnum::MinusAssign   => AssignOpEnum::Sub,
            TokenTypeEnum::StarAssign    => AssignOpEnum::Mul,
            TokenTypeEnum::SlashAssign   => AssignOpEnum::Div,
            TokenTypeEnum::PercentAssign => AssignOpEnum::Mod,
            default                      => AssignOpEnum::Alt,
        };
    }

    private function binaryOp(TokenTypeEnum $type): BinaryOpEnum
    {
        return match ($type) {
            TokenTypeEnum::KwOr    => BinaryOpEnum::Or,
            TokenTypeEnum::KwAnd   => BinaryOpEnum::And,
            TokenTypeEnum::Eq      => BinaryOpEnum::Eq,
            TokenTypeEnum::Neq     => BinaryOpEnum::Neq,
            TokenTypeEnum::Lt      => BinaryOpEnum::Lt,
            TokenTypeEnum::Le      => BinaryOpEnum::Le,
            TokenTypeEnum::Gt      => BinaryOpEnum::Gt,
            TokenTypeEnum::Ge      => BinaryOpEnum::Ge,
            TokenTypeEnum::Plus    => BinaryOpEnum::Add,
            TokenTypeEnum::Minus   => BinaryOpEnum::Sub,
            TokenTypeEnum::Star    => BinaryOpEnum::Mul,
            TokenTypeEnum::Slash   => BinaryOpEnum::Div,
            default                => BinaryOpEnum::Mod,
        };
    }

    /**
     * Non-associative operators cannot be chained: the next operator of the same level is a syntax error.
     */
    private function rejectChain(int $level): void
    {
        $next = $this->tokens[$this->pos];
        if ((self::LEVEL[$next->type->value] ?? 0) === $level) {
            throw $this->unexpected($next);
        }
    }

    private function parseUnary(): NodeInterface
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenTypeEnum::Minus:
                ++$this->pos;

                return new Negate($this->parseExpr(self::LEVEL_MUL));

            case TokenTypeEnum::KwDef:
                $def = $this->parseDef();

                return new FuncDefScope($def, $this->parsePipe());

            case TokenTypeEnum::KwLabel:
                ++$this->pos;
                $name = $this->expect(TokenTypeEnum::Variable);
                $this->expect(TokenTypeEnum::Pipe);

                return new Label($name->text, $this->parsePipe());

            default:
                return $this->parsePostfix();
        }
    }

    private function parsePostfix(): NodeInterface
    {
        $term = $this->parsePrimary();
        while (true) {
            $tok = $this->tokens[$this->pos];
            switch ($tok->type) {
                case TokenTypeEnum::Field:
                    ++$this->pos;
                    $term = new Index($term, new Literal($tok->text));

                    break;

                case TokenTypeEnum::Dot:
                    $next = $this->tokens[$this->pos + 1] ?? null;
                    if (null === $next) {
                        return $term;
                    }

                    if (TokenTypeEnum::StringStart === $next->type) {
                        ++$this->pos;
                        $term = new Index($term, $this->parseString(null));

                        break;
                    }

                    if (TokenTypeEnum::LBracket === $next->type) {
                        $this->pos += 2;
                        $term = $this->parseBracket($term);

                        break;
                    }

                    return $term;

                case TokenTypeEnum::LBracket:
                    ++$this->pos;
                    $term = $this->parseBracket($term);

                    break;

                case TokenTypeEnum::Question:
                    ++$this->pos;
                    $term = new TryCatch($term, null);

                    break;

                default:
                    return $term;
            }
        }
    }

    /**
     * After `[`: `]`, `e]`, `e:]`, `e:e]`, `:e]`.
     */
    private function parseBracket(NodeInterface $target): NodeInterface
    {
        $type = $this->tokens[$this->pos]->type;
        if (TokenTypeEnum::RBracket === $type) {
            ++$this->pos;

            return new Iterate($target);
        }

        if (TokenTypeEnum::Colon === $type) {
            ++$this->pos;
            $to = $this->parsePipe();
            $this->expect(TokenTypeEnum::RBracket);

            return new Slice($target, null, $to);
        }

        $from = $this->parsePipe();
        if (TokenTypeEnum::Colon === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $to = null;
            if (TokenTypeEnum::RBracket !== $this->tokens[$this->pos]->type) {
                $to = $this->parsePipe();
            }

            $this->expect(TokenTypeEnum::RBracket);

            return new Slice($target, $from, $to);
        }

        $this->expect(TokenTypeEnum::RBracket);

        return new Index($target, $from);
    }

    private function parsePrimary(): NodeInterface
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenTypeEnum::Dot:
                ++$this->pos;
                if (TokenTypeEnum::StringStart === $this->tokens[$this->pos]->type) {
                    return new Index(new Identity(), $this->parseString(null));
                }

                return new Identity();

            case TokenTypeEnum::DotDot:
                ++$this->pos;

                return new FunctionCall('recurse', [], $tok->line);

            case TokenTypeEnum::Field:
                ++$this->pos;

                return new Index(new Identity(), new Literal($tok->text));

            case TokenTypeEnum::Number:
                ++$this->pos;

                return new NumberLiteral($tok->text);

            case TokenTypeEnum::StringStart:
                return $this->parseString(null);

            case TokenTypeEnum::Format:
                ++$this->pos;
                if (TokenTypeEnum::StringStart === $this->tokens[$this->pos]->type) {
                    return $this->parseString($tok->text);
                }

                return new Format($tok->text);

            case TokenTypeEnum::Variable:
                ++$this->pos;
                if ('__loc__' === $tok->text) {
                    return new Location(self::TOP_LEVEL_FILE, $tok->line);
                }

                return new Variable($tok->text, $tok->line);

            case TokenTypeEnum::LParen:
                ++$this->pos;
                $inner = $this->parsePipe();
                $this->expect(TokenTypeEnum::RParen);

                return $inner;

            case TokenTypeEnum::LBracket:
                ++$this->pos;
                if (TokenTypeEnum::RBracket === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ArrayConstruct(null);
                }

                $body = $this->parsePipe();
                $this->expect(TokenTypeEnum::RBracket);

                return new ArrayConstruct($body);

            case TokenTypeEnum::LBrace:
                ++$this->pos;

                return $this->parseObject();

            case TokenTypeEnum::Ident:
                return $this->parseIdent($tok);

            case TokenTypeEnum::KwIf:
                ++$this->pos;

                return $this->parseIfRest();

            case TokenTypeEnum::KwReduce:
                return $this->parseReduce();

            case TokenTypeEnum::KwForeach:
                return $this->parseForeach();

            case TokenTypeEnum::KwTry:
                ++$this->pos;
                try {
                    $body = $this->parseTryOperand();
                } catch (JqCompileException $jqCompileException) {
                    throw $this->annotateTry($jqCompileException, $tok);
                }

                $handler = null;
                if (TokenTypeEnum::KwCatch === $this->tokens[$this->pos]->type) {
                    ++$this->pos;
                    $handler = $this->parseTryOperand();
                }

                return new TryCatch($body, $handler);

            default:
                throw $this->unexpected($tok);
        }
    }

    /**
     * The body and handler of `try` are postfix terms (the grammar gives `try` the highest precedence), or a
     * negated one.
     */
    private function parseTryOperand(): NodeInterface
    {
        if (TokenTypeEnum::Minus === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new Negate($this->parseTryOperand());
        }

        return $this->parsePostfix();
    }

    private function parseIdent(Token $tok): NodeInterface
    {
        ++$this->pos;
        $name = $tok->text;
        $next = $this->tokens[$this->pos];
        if (TokenTypeEnum::LParen === $next->type) {
            ++$this->pos;
            $args = [$this->parsePipe()];
            while (TokenTypeEnum::Semicolon === $this->tokens[$this->pos]->type) {
                ++$this->pos;
                $args[] = $this->parsePipe();
            }

            $this->expect(TokenTypeEnum::RParen);

            return new FunctionCall($name, $args, $tok->line);
        }

        switch ($name) {
            case 'true':
                return new Literal(true);

            case 'false':
                return new Literal(false);

            case 'null':
                return new Literal(null);

            case 'break':
                if (TokenTypeEnum::Variable !== $next->type) {
                    throw $this->unexpected($next);
                }

                ++$this->pos;

                return new BreakOut($next->text, $tok->line);

            default:
                return new FunctionCall($name, [], $tok->line);
        }
    }

    /**
     * After `if` or `elif`: condition, then-branch and the else chain up to `end`.
     */
    private function parseIfRest(): NodeInterface
    {
        $opener    = $this->tokens[$this->pos - 1];
        $condition = $this->parsePipe();
        $this->expect(TokenTypeEnum::KwThen);

        try {
            return $this->parseIfBranches($condition);
        } catch (JqCompileException $jqCompileException) {
            // like jq's `"if" Exp "then" error` recovery: the innermost construct past its `then` is reported
            if ($this->unterminated) {
                throw $jqCompileException;
            }

            $this->unterminated = true;

            throw new JqCompileException($jqCompileException->getMessage() . $this->unterminatedNote('if', $opener), $jqCompileException->getCode(), $jqCompileException);
        }
    }

    private function parseIfBranches(NodeInterface $condition): NodeInterface
    {
        $then = $this->parsePipe();
        $else = null;
        $tok  = $this->current();
        if (TokenTypeEnum::KwElif === $tok->type) {
            ++$this->pos;

            return new IfThenElse($condition, $then, $this->parseIfRest());
        }

        if (TokenTypeEnum::KwElse === $tok->type) {
            ++$this->pos;
            $else = $this->parsePipe();
            if (TokenTypeEnum::KwEnd !== $this->current()->type) {
                throw $this->unexpected($this->current(), "end or '|' or ','");
            }
        }

        $this->expect(TokenTypeEnum::KwEnd);

        return new IfThenElse($condition, $then, $else);
    }

    /**
     * jq recovers from an unterminated `if` as an expression, so a `catch` that follows it is shifted and,
     * when what comes after it cannot continue the handler, reports the `try` as unterminated as well.
     */
    private function annotateTry(JqCompileException $error, Token $try): JqCompileException
    {
        $current = $this->tokens[$this->pos];
        $after   = $this->tokens[$this->pos + 1] ?? null;
        if (!$this->unterminated || TokenTypeEnum::KwCatch !== $current->type || null === $after) {
            return $error;
        }

        $terminators = [
            TokenTypeEnum::Eof, TokenTypeEnum::RBracket, TokenTypeEnum::RParen, TokenTypeEnum::RBrace,
            TokenTypeEnum::Pipe, TokenTypeEnum::Comma, TokenTypeEnum::Semicolon,
        ];
        if (!\in_array($after->type, $terminators, true)) {
            return $error;
        }

        return new JqCompileException($error->getMessage() . $this->unterminatedNote('try', $try));
    }

    private function unterminatedNote(string $construct, Token $opener): string
    {
        return \sprintf(
            "\njq: error: Possibly unterminated '%s' statement at %s, line %d, column %d:",
            $construct,
            self::TOP_LEVEL_FILE,
            $opener->line,
            $opener->column,
        );
    }

    private function parseReduce(): NodeInterface
    {
        ++$this->pos;
        $source  = $this->parseLoopSource();
        $pattern = $this->parseSinglePattern();
        $this->expect(TokenTypeEnum::LParen);
        $init = $this->parsePipe();
        $this->expect(TokenTypeEnum::Semicolon);
        $update = $this->parsePipe();
        $this->expect(TokenTypeEnum::RParen);

        return new Reduce($source, $pattern, $init, $update);
    }

    private function parseForeach(): NodeInterface
    {
        ++$this->pos;
        $source  = $this->parseLoopSource();
        $pattern = $this->parseSinglePattern();
        $this->expect(TokenTypeEnum::LParen);
        $init = $this->parsePipe();
        $this->expect(TokenTypeEnum::Semicolon);
        $update  = $this->parsePipe();
        $extract = null;
        if (TokenTypeEnum::Semicolon === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $extract = $this->parsePipe();
        }

        $this->expect(TokenTypeEnum::RParen);

        return new ForeachLoop($source, $pattern, $init, $update, $extract);
    }

    /**
     * `as pattern` of reduce/foreach; destructuring alternatives are not valid here.
     */
    private function parseSinglePattern(): PatternInterface
    {
        $this->expect(TokenTypeEnum::KwAs);

        return $this->parsePattern();
    }

    /**
     * @return non-empty-list<PatternInterface>
     */
    private function parsePatterns(): array
    {
        $patterns = [$this->parsePattern()];
        while (TokenTypeEnum::DestructAlt === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $patterns[] = $this->parsePattern();
        }

        return $patterns;
    }

    private function parsePattern(): PatternInterface
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenTypeEnum::Variable:
                ++$this->pos;

                return new VariablePattern($tok->text);

            case TokenTypeEnum::LBracket:
                ++$this->pos;
                $elements = [$this->parsePattern()];
                while (TokenTypeEnum::Comma === $this->tokens[$this->pos]->type) {
                    ++$this->pos;
                    $elements[] = $this->parsePattern();
                }

                $this->expect(TokenTypeEnum::RBracket);

                return new ArrayPattern($elements);

            case TokenTypeEnum::LBrace:
                ++$this->pos;
                $entries = [$this->parseObjectPatternEntry()];
                while (TokenTypeEnum::Comma === $this->tokens[$this->pos]->type) {
                    ++$this->pos;
                    $entries[] = $this->parseObjectPatternEntry();
                }

                $this->expect(TokenTypeEnum::RBrace);

                return new ObjectPattern($entries);

            default:
                throw $this->unexpected($tok, "BINDING or '[' or '{'");
        }
    }

    private function parseObjectPatternEntry(): ObjectPatternEntry
    {
        $tok = $this->tokens[$this->pos];
        if (TokenTypeEnum::Variable === $tok->type) {
            ++$this->pos;
            if (TokenTypeEnum::Colon !== $this->tokens[$this->pos]->type) {
                return new ObjectPatternEntry($tok->text, null, null);
            }

            ++$this->pos;

            return new ObjectPatternEntry($tok->text, null, $this->parsePattern());
        }

        if (TokenTypeEnum::Ident === $tok->type || $this->isKeyword($tok)) {
            ++$this->pos;
            $key = new Literal($tok->text);
        } elseif (TokenTypeEnum::StringStart === $tok->type || TokenTypeEnum::Format === $tok->type) {
            $key = $this->parseKeyString();
        } elseif (TokenTypeEnum::LParen === $tok->type) {
            ++$this->pos;
            $key = $this->parsePipe();
            $this->expect(TokenTypeEnum::RParen);
        } else {
            throw $this->unexpected($tok);
        }

        $this->expect(TokenTypeEnum::Colon);

        return new ObjectPatternEntry(null, $key, $this->parsePattern());
    }

    /**
     * A string or `@format "string"` used as an object (pattern) key.
     */
    private function parseKeyString(): NodeInterface
    {
        $tok = $this->tokens[$this->pos];
        if (TokenTypeEnum::Format === $tok->type) {
            ++$this->pos;
            if (TokenTypeEnum::StringStart !== $this->tokens[$this->pos]->type) {
                throw $this->unexpected($this->current());
            }

            return $this->parseString($tok->text);
        }

        return $this->parseString(null);
    }

    private function parseObject(): NodeInterface
    {
        if (TokenTypeEnum::RBrace === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new ObjectConstruct([]);
        }

        $entries = [];
        while (true) {
            $entries[] = $this->parseObjectEntry();
            $tok       = $this->current();
            if (TokenTypeEnum::Comma === $tok->type) {
                ++$this->pos;
                if (TokenTypeEnum::RBrace === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    break;
                }

                continue;
            }

            if (TokenTypeEnum::RBrace === $tok->type) {
                ++$this->pos;

                break;
            }

            throw $this->unexpected($tok);
        }

        return new ObjectConstruct($entries);
    }

    private function parseObjectEntry(): ObjectEntry
    {
        $tok = $this->tokens[$this->pos];
        switch (true) {
            case TokenTypeEnum::Variable === $tok->type:
                ++$this->pos;
                if (TokenTypeEnum::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry(new Variable($tok->text, $tok->line), $this->parseObjectValue());
                }

                if ('__loc__' === $tok->text) {
                    return new ObjectEntry(new Literal('__loc__'), new Location(self::TOP_LEVEL_FILE, $tok->line));
                }

                return new ObjectEntry(new Literal($tok->text), new Variable($tok->text, $tok->line));

            case TokenTypeEnum::Ident === $tok->type || $this->isKeyword($tok):
                ++$this->pos;
                if (TokenTypeEnum::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry(new Literal($tok->text), $this->parseObjectValue());
                }

                return new ObjectEntry(new Literal($tok->text), new Index(new Identity(), new Literal($tok->text)));

            case TokenTypeEnum::StringStart === $tok->type || TokenTypeEnum::Format === $tok->type:
                $key = $this->parseKeyString();
                if (TokenTypeEnum::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry($key, $this->parseObjectValue());
                }

                return new ObjectEntry($key, new Index(new Identity(), $key));

            case TokenTypeEnum::LParen === $tok->type:
                ++$this->pos;
                $key = $this->parsePipe();
                $this->expect(TokenTypeEnum::RParen);
                $this->expect(TokenTypeEnum::Colon);

                return new ObjectEntry($key, $this->parseObjectValue());

            default:
                if ($this->colonFollows()) {
                    throw $this->compileError('May need parentheses around object key expression', $tok);
                }

                throw $this->unexpected($tok);
        }
    }

    /**
     * Whether a `:` appears before the object entry ends, which jq reports as a bad key expression.
     */
    private function colonFollows(): bool
    {
        $depth = 0;
        for ($i = $this->pos, $n = \count($this->tokens); $i < $n; ++$i) {
            switch ($this->tokens[$i]->type) {
                case TokenTypeEnum::LParen:
                case TokenTypeEnum::LBracket:
                case TokenTypeEnum::LBrace:
                    ++$depth;

                    break;

                case TokenTypeEnum::RParen:
                case TokenTypeEnum::RBracket:
                case TokenTypeEnum::RBrace:
                    if (0 === $depth) {
                        return false;
                    }

                    --$depth;

                    break;

                case TokenTypeEnum::Colon:
                    if (0 === $depth) {
                        return true;
                    }

                    break;

                case TokenTypeEnum::Comma:
                    if (0 === $depth) {
                        return false;
                    }

                    break;

                case TokenTypeEnum::Eof:
                    return false;

                default:
            }
        }

        return false;
    }

    /**
     * An object value: postfix terms, optionally negated, joined with `|` (no other binary operators).
     */
    private function parseObjectValue(): NodeInterface
    {
        if (TokenTypeEnum::Minus === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $value = new Negate($this->parseExpr(self::LEVEL_MUL));
        } else {
            $value = $this->parseExpr(self::LEVEL_ALT);
        }

        if (TokenTypeEnum::Pipe === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new Pipe($value, $this->parseObjectValue());
        }

        return $value;
    }

    /**
     * A string literal starting at StringStart. Without interpolation it is a Literal.
     */
    private function parseString(?string $format): NodeInterface
    {
        ++$this->pos;
        $parts        = [];
        $text         = '';
        $interpolated = false;
        while (true) {
            $tok = $this->tokens[$this->pos];
            switch ($tok->type) {
                case TokenTypeEnum::StringFragment:
                    ++$this->pos;
                    $parts[] = $tok->text;
                    $text .= $tok->text;

                    break;

                case TokenTypeEnum::InterpStart:
                    ++$this->pos;
                    $parts[]      = $this->parsePipe();
                    $interpolated = true;
                    $this->expect(TokenTypeEnum::InterpEnd);

                    break;

                case TokenTypeEnum::StringEnd:
                    ++$this->pos;
                    if (!$interpolated) {
                        return new Literal($text);
                    }

                    return new StringInterpolation($format, $parts);

                default:
                    throw $this->unexpected($tok);
            }
        }
    }

    private function isKeyword(Token $tok): bool
    {
        return match ($tok->type) {
            TokenTypeEnum::KwDef, TokenTypeEnum::KwIf, TokenTypeEnum::KwThen, TokenTypeEnum::KwElif, TokenTypeEnum::KwElse,
            TokenTypeEnum::KwEnd, TokenTypeEnum::KwAs, TokenTypeEnum::KwReduce, TokenTypeEnum::KwForeach, TokenTypeEnum::KwTry,
            TokenTypeEnum::KwCatch, TokenTypeEnum::KwLabel, TokenTypeEnum::KwImport, TokenTypeEnum::KwInclude,
            TokenTypeEnum::KwModule, TokenTypeEnum::KwAnd, TokenTypeEnum::KwOr => true,
            default                                                            => false,
        };
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(TokenTypeEnum $type): Token
    {
        $tok = $this->tokens[$this->pos];
        if ($tok->type !== $type) {
            throw $this->unexpected($tok);
        }

        ++$this->pos;

        return $tok;
    }

    private function unexpected(Token $tok, ?string $expecting = null): JqCompileException
    {
        if (null === $expecting && 0 === $this->pos && TokenTypeEnum::Eof !== $tok->type) {
            $expecting = 'end of file';
        }

        return new JqCompileException(\sprintf(
            'syntax error, unexpected %s%s at %s, line %d, column %d:',
            self::TOKEN_NAMES[$tok->type->value] ?? ('' === $tok->text ? $tok->type->value : $tok->text),
            null === $expecting ? '' : ', expecting ' . $expecting,
            self::TOP_LEVEL_FILE,
            $tok->line,
            $tok->column,
        ));
    }

    private function compileError(string $message, Token $tok): JqCompileException
    {
        return new JqCompileException(\sprintf(
            '%s at %s, line %d, column %d:',
            $message,
            self::TOP_LEVEL_FILE,
            $tok->line,
            $tok->column,
        ));
    }
}
