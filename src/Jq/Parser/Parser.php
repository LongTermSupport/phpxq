<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LTS\PhpXq\Jq\Ast\ArrayConstruct;
use LTS\PhpXq\Jq\Ast\ArrayPattern;
use LTS\PhpXq\Jq\Ast\Assign;
use LTS\PhpXq\Jq\Ast\AssignOp;
use LTS\PhpXq\Jq\Ast\Binary;
use LTS\PhpXq\Jq\Ast\BinaryOp;
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
use LTS\PhpXq\Jq\Ast\ImportKind;
use LTS\PhpXq\Jq\Ast\Index;
use LTS\PhpXq\Jq\Ast\Iterate;
use LTS\PhpXq\Jq\Ast\Label;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Location;
use LTS\PhpXq\Jq\Ast\ModuleDirective;
use LTS\PhpXq\Jq\Ast\Negate;
use LTS\PhpXq\Jq\Ast\Node;
use LTS\PhpXq\Jq\Ast\NumberLiteral;
use LTS\PhpXq\Jq\Ast\ObjectConstruct;
use LTS\PhpXq\Jq\Ast\ObjectEntry;
use LTS\PhpXq\Jq\Ast\ObjectPattern;
use LTS\PhpXq\Jq\Ast\ObjectPatternEntry;
use LTS\PhpXq\Jq\Ast\Pattern;
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

    public function __construct(private readonly LexerInterface $lexer)
    {
    }

    public function parse(string $source): Program
    {
        $this->tokens = $this->lexer->tokenize($source);
        $this->pos    = 0;

        try {
            return $this->parseProgram();
        } finally {
            $this->tokens = [];
        }
    }

    private function parseProgram(): Program
    {
        $module = null;
        if (TokenType::KwModule === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $metaTok = $this->current();
            $meta    = $this->parsePipe();
            $this->expect(TokenType::Semicolon);
            $module = new ModuleDirective($this->metadata($meta, $metaTok));
        }

        $imports = [];
        while (TokenType::KwImport === $this->tokens[$this->pos]->type || TokenType::KwInclude === $this->tokens[$this->pos]->type) {
            $imports[] = $this->parseImport();
        }

        $defs = [];
        while (TokenType::KwDef === $this->tokens[$this->pos]->type) {
            $defs[] = $this->parseDef();
        }

        $body = null;
        if (TokenType::Eof !== $this->tokens[$this->pos]->type) {
            $body = $this->parsePipe();
            $tail = $this->tokens[$this->pos];
            if (TokenType::Eof !== $tail->type) {
                throw $this->unexpected($tail);
            }
        }

        return new Program($imports, $module, $defs, $body);
    }

    private function parseImport(): ImportDirective
    {
        $keyword = $this->advance();
        $pathTok = $this->current();
        if (TokenType::StringStart !== $pathTok->type) {
            throw $this->unexpected($pathTok);
        }

        $path = $this->parseString(null);
        if (!$path instanceof Literal || !\is_string($path->value)) {
            throw $this->compileError('Import path must be constant', $pathTok);
        }

        $kind  = ImportKind::Include;
        $alias = null;
        if (TokenType::KwImport === $keyword->type) {
            $this->expect(TokenType::KwAs);
            $name = $this->current();
            if (TokenType::Variable === $name->type) {
                $kind = ImportKind::Data;
            } elseif (TokenType::Ident === $name->type) {
                $kind = ImportKind::Import;
            } else {
                throw $this->unexpected($name);
            }

            $alias = $name->text;
            ++$this->pos;
        }

        $metadata = null;
        if (TokenType::Semicolon !== $this->tokens[$this->pos]->type) {
            $metaTok  = $this->current();
            $metadata = $this->metadata($this->parsePipe(), $metaTok);
        }

        $this->expect(TokenType::Semicolon);

        return new ImportDirective($path->value, $alias, $kind, $metadata);
    }

    /**
     * Fold a constant object expression (module and import metadata) into the value model.
     */
    private function metadata(Node $node, Token $at): JsonObject
    {
        $folded = $this->fold($node, $at);
        if (!$folded instanceof JsonObject) {
            throw $this->compileError('Module metadata must be an object', $at);
        }

        return $folded;
    }

    private function fold(Node $node, Token $at): mixed
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
     * @return list<Node>
     */
    private function flattenComma(?Node $node): array
    {
        if (!$node instanceof \LTS\PhpXq\Jq\Ast\Node) {
            return [];
        }

        if ($node instanceof Comma) {
            return [...$this->flattenComma($node->left), ...$this->flattenComma($node->right)];
        }

        return [$node];
    }

    private function parseDef(): FuncDef
    {
        $def  = $this->advance();
        $name = $this->current();
        if (TokenType::Ident !== $name->type) {
            throw $this->unexpected($name);
        }

        ++$this->pos;
        $params = [];
        if (TokenType::LParen === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            while (true) {
                $param = $this->current();
                if (TokenType::Variable === $param->type) {
                    $params[] = '$' . $param->text;
                } elseif (TokenType::Ident === $param->type) {
                    $params[] = $param->text;
                } else {
                    throw $this->unexpected($param);
                }

                ++$this->pos;
                if (TokenType::Semicolon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    continue;
                }

                $this->expect(TokenType::RParen);

                break;
            }
        }

        $this->expect(TokenType::Colon);
        $body = $this->parsePipe();
        $this->expect(TokenType::Semicolon);

        return new FuncDef($name->text, $params, $body, $def->line);
    }

    private function parsePipe(): Node
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
    private function parseLoopSource(): Node
    {
        $saved                = $this->bindingAllowed;
        $this->bindingAllowed = false;
        try {
            return $this->parseExpr(self::LEVEL_ALT);
        } finally {
            $this->bindingAllowed = $saved;
        }
    }

    private function parseExpr(int $min): Node
    {
        $left = $this->parseUnary();
        while (true) {
            $tok   = $this->tokens[$this->pos];
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
                    $left = new Binary(BinaryOp::Alt, $left, $this->parseExpr(self::LEVEL_ALT));

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

    private function assignOp(TokenType $type): AssignOp
    {
        return match ($type) {
            TokenType::Assign        => AssignOp::Set,
            TokenType::UpdateAssign  => AssignOp::Update,
            TokenType::PlusAssign    => AssignOp::Add,
            TokenType::MinusAssign   => AssignOp::Sub,
            TokenType::StarAssign    => AssignOp::Mul,
            TokenType::SlashAssign   => AssignOp::Div,
            TokenType::PercentAssign => AssignOp::Mod,
            default                  => AssignOp::Alt,
        };
    }

    private function binaryOp(TokenType $type): BinaryOp
    {
        return match ($type) {
            TokenType::KwOr    => BinaryOp::Or,
            TokenType::KwAnd   => BinaryOp::And,
            TokenType::Eq      => BinaryOp::Eq,
            TokenType::Neq     => BinaryOp::Neq,
            TokenType::Lt      => BinaryOp::Lt,
            TokenType::Le      => BinaryOp::Le,
            TokenType::Gt      => BinaryOp::Gt,
            TokenType::Ge      => BinaryOp::Ge,
            TokenType::Plus    => BinaryOp::Add,
            TokenType::Minus   => BinaryOp::Sub,
            TokenType::Star    => BinaryOp::Mul,
            TokenType::Slash   => BinaryOp::Div,
            default            => BinaryOp::Mod,
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

    private function parseUnary(): Node
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenType::Minus:
                ++$this->pos;

                return new Negate($this->parseExpr(self::LEVEL_MUL));

            case TokenType::KwDef:
                $def = $this->parseDef();

                return new FuncDefScope($def, $this->parsePipe());

            case TokenType::KwLabel:
                ++$this->pos;
                $name = $this->expect(TokenType::Variable);
                $this->expect(TokenType::Pipe);

                return new Label($name->text, $this->parsePipe());

            default:
                $term = $this->parsePostfix();
                if (!$this->bindingAllowed || TokenType::KwAs !== $this->tokens[$this->pos]->type) {
                    return $term;
                }

                ++$this->pos;
                $patterns = $this->parsePatterns();
                $this->expect(TokenType::Pipe);

                return new Bind($term, $patterns, $this->parsePipe());
        }
    }

    private function parsePostfix(): Node
    {
        $term = $this->parsePrimary();
        while (true) {
            $tok = $this->tokens[$this->pos];
            switch ($tok->type) {
                case TokenType::Field:
                    ++$this->pos;
                    $term = new Index($term, new Literal($tok->text));

                    break;

                case TokenType::Dot:
                    $next = $this->tokens[$this->pos + 1] ?? null;
                    if (null === $next) {
                        return $term;
                    }

                    if (TokenType::StringStart === $next->type) {
                        ++$this->pos;
                        $term = new Index($term, $this->parseString(null));

                        break;
                    }

                    if (TokenType::LBracket === $next->type) {
                        $this->pos += 2;
                        $term = $this->parseBracket($term);

                        break;
                    }

                    return $term;

                case TokenType::LBracket:
                    ++$this->pos;
                    $term = $this->parseBracket($term);

                    break;

                case TokenType::Question:
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
    private function parseBracket(Node $target): Node
    {
        $type = $this->tokens[$this->pos]->type;
        if (TokenType::RBracket === $type) {
            ++$this->pos;

            return new Iterate($target);
        }

        if (TokenType::Colon === $type) {
            ++$this->pos;
            $to = $this->parsePipe();
            $this->expect(TokenType::RBracket);

            return new Slice($target, null, $to);
        }

        $from = $this->parsePipe();
        if (TokenType::Colon === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $to = null;
            if (TokenType::RBracket !== $this->tokens[$this->pos]->type) {
                $to = $this->parsePipe();
            }

            $this->expect(TokenType::RBracket);

            return new Slice($target, $from, $to);
        }

        $this->expect(TokenType::RBracket);

        return new Index($target, $from);
    }

    private function parsePrimary(): Node
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenType::Dot:
                ++$this->pos;
                if (TokenType::StringStart === $this->tokens[$this->pos]->type) {
                    return new Index(new Identity(), $this->parseString(null));
                }

                return new Identity();

            case TokenType::DotDot:
                ++$this->pos;

                return new FunctionCall('recurse', [], $tok->line);

            case TokenType::Field:
                ++$this->pos;

                return new Index(new Identity(), new Literal($tok->text));

            case TokenType::Number:
                ++$this->pos;

                return new NumberLiteral($tok->text);

            case TokenType::StringStart:
                return $this->parseString(null);

            case TokenType::Format:
                ++$this->pos;
                if (TokenType::StringStart === $this->tokens[$this->pos]->type) {
                    return $this->parseString($tok->text);
                }

                return new Format($tok->text);

            case TokenType::Variable:
                ++$this->pos;
                if ('__loc__' === $tok->text) {
                    return new Location(self::TOP_LEVEL_FILE, $tok->line);
                }

                return new Variable($tok->text, $tok->line);

            case TokenType::LParen:
                ++$this->pos;
                $inner = $this->parsePipe();
                $this->expect(TokenType::RParen);

                return $inner;

            case TokenType::LBracket:
                ++$this->pos;
                if (TokenType::RBracket === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ArrayConstruct(null);
                }

                $body = $this->parsePipe();
                $this->expect(TokenType::RBracket);

                return new ArrayConstruct($body);

            case TokenType::LBrace:
                ++$this->pos;

                return $this->parseObject();

            case TokenType::Ident:
                return $this->parseIdent($tok);

            case TokenType::KwIf:
                ++$this->pos;

                return $this->parseIfRest();

            case TokenType::KwReduce:
                return $this->parseReduce();

            case TokenType::KwForeach:
                return $this->parseForeach();

            case TokenType::KwTry:
                ++$this->pos;
                $body    = $this->parseTryOperand();
                $handler = null;
                if (TokenType::KwCatch === $this->tokens[$this->pos]->type) {
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
    private function parseTryOperand(): Node
    {
        if (TokenType::Minus === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new Negate($this->parseTryOperand());
        }

        return $this->parsePostfix();
    }

    private function parseIdent(Token $tok): Node
    {
        ++$this->pos;
        $name = $tok->text;
        $next = $this->tokens[$this->pos];
        if (TokenType::LParen === $next->type) {
            ++$this->pos;
            $args = [$this->parsePipe()];
            while (TokenType::Semicolon === $this->tokens[$this->pos]->type) {
                ++$this->pos;
                $args[] = $this->parsePipe();
            }

            $this->expect(TokenType::RParen);

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
                if (TokenType::Variable !== $next->type) {
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
    private function parseIfRest(): Node
    {
        $condition = $this->parsePipe();
        $this->expect(TokenType::KwThen);
        $then = $this->parsePipe();
        $else = null;
        $tok  = $this->current();
        if (TokenType::KwElif === $tok->type) {
            ++$this->pos;

            return new IfThenElse($condition, $then, $this->parseIfRest());
        }

        if (TokenType::KwElse === $tok->type) {
            ++$this->pos;
            $else = $this->parsePipe();
        }

        $this->expect(TokenType::KwEnd);

        return new IfThenElse($condition, $then, $else);
    }

    private function parseReduce(): Node
    {
        ++$this->pos;
        $source  = $this->parseLoopSource();
        $pattern = $this->parseSinglePattern();
        $this->expect(TokenType::LParen);
        $init = $this->parsePipe();
        $this->expect(TokenType::Semicolon);
        $update = $this->parsePipe();
        $this->expect(TokenType::RParen);

        return new Reduce($source, $pattern, $init, $update);
    }

    private function parseForeach(): Node
    {
        ++$this->pos;
        $source  = $this->parseLoopSource();
        $pattern = $this->parseSinglePattern();
        $this->expect(TokenType::LParen);
        $init = $this->parsePipe();
        $this->expect(TokenType::Semicolon);
        $update  = $this->parsePipe();
        $extract = null;
        if (TokenType::Semicolon === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $extract = $this->parsePipe();
        }

        $this->expect(TokenType::RParen);

        return new ForeachLoop($source, $pattern, $init, $update, $extract);
    }

    /**
     * `as pattern` of reduce/foreach; destructuring alternatives are not valid here.
     */
    private function parseSinglePattern(): Pattern
    {
        $this->expect(TokenType::KwAs);

        return $this->parsePattern();
    }

    /**
     * @return non-empty-list<Pattern>
     */
    private function parsePatterns(): array
    {
        $patterns = [$this->parsePattern()];
        while (TokenType::DestructAlt === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $patterns[] = $this->parsePattern();
        }

        return $patterns;
    }

    private function parsePattern(): Pattern
    {
        $tok = $this->tokens[$this->pos];
        switch ($tok->type) {
            case TokenType::Variable:
                ++$this->pos;

                return new VariablePattern($tok->text);

            case TokenType::LBracket:
                ++$this->pos;
                $elements = [$this->parsePattern()];
                while (TokenType::Comma === $this->tokens[$this->pos]->type) {
                    ++$this->pos;
                    $elements[] = $this->parsePattern();
                }

                $this->expect(TokenType::RBracket);

                return new ArrayPattern($elements);

            case TokenType::LBrace:
                ++$this->pos;
                $entries = [$this->parseObjectPatternEntry()];
                while (TokenType::Comma === $this->tokens[$this->pos]->type) {
                    ++$this->pos;
                    $entries[] = $this->parseObjectPatternEntry();
                }

                $this->expect(TokenType::RBrace);

                return new ObjectPattern($entries);

            default:
                throw $this->unexpected($tok, "BINDING or '[' or '{'");
        }
    }

    private function parseObjectPatternEntry(): ObjectPatternEntry
    {
        $tok = $this->tokens[$this->pos];
        if (TokenType::Variable === $tok->type) {
            ++$this->pos;
            if (TokenType::Colon !== $this->tokens[$this->pos]->type) {
                return new ObjectPatternEntry($tok->text, null, null);
            }

            ++$this->pos;

            return new ObjectPatternEntry($tok->text, null, $this->parsePattern());
        }

        if (TokenType::Ident === $tok->type || $this->isKeyword($tok)) {
            ++$this->pos;
            $key = new Literal($tok->text);
        } elseif (TokenType::StringStart === $tok->type || TokenType::Format === $tok->type) {
            $key = $this->parseKeyString();
        } elseif (TokenType::LParen === $tok->type) {
            ++$this->pos;
            $key = $this->parsePipe();
            $this->expect(TokenType::RParen);
        } else {
            throw $this->unexpected($tok);
        }

        $this->expect(TokenType::Colon);

        return new ObjectPatternEntry(null, $key, $this->parsePattern());
    }

    /**
     * A string or `@format "string"` used as an object (pattern) key.
     */
    private function parseKeyString(): Node
    {
        $tok = $this->tokens[$this->pos];
        if (TokenType::Format === $tok->type) {
            ++$this->pos;
            if (TokenType::StringStart !== $this->tokens[$this->pos]->type) {
                throw $this->unexpected($this->current());
            }

            return $this->parseString($tok->text);
        }

        return $this->parseString(null);
    }

    private function parseObject(): Node
    {
        if (TokenType::RBrace === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new ObjectConstruct([]);
        }

        $entries = [];
        while (true) {
            $entries[] = $this->parseObjectEntry();
            $tok       = $this->current();
            if (TokenType::Comma === $tok->type) {
                ++$this->pos;
                if (TokenType::RBrace === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    break;
                }

                continue;
            }

            if (TokenType::RBrace === $tok->type) {
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
            case TokenType::Variable === $tok->type:
                ++$this->pos;
                if (TokenType::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry(new Variable($tok->text, $tok->line), $this->parseObjectValue());
                }

                if ('__loc__' === $tok->text) {
                    return new ObjectEntry(new Literal('__loc__'), new Location(self::TOP_LEVEL_FILE, $tok->line));
                }

                return new ObjectEntry(new Literal($tok->text), new Variable($tok->text, $tok->line));

            case TokenType::Ident === $tok->type || $this->isKeyword($tok):
                ++$this->pos;
                if (TokenType::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry(new Literal($tok->text), $this->parseObjectValue());
                }

                return new ObjectEntry(new Literal($tok->text), new Index(new Identity(), new Literal($tok->text)));

            case TokenType::StringStart === $tok->type || TokenType::Format === $tok->type:
                $key = $this->parseKeyString();
                if (TokenType::Colon === $this->tokens[$this->pos]->type) {
                    ++$this->pos;

                    return new ObjectEntry($key, $this->parseObjectValue());
                }

                return new ObjectEntry($key, new Index(new Identity(), $key));

            case TokenType::LParen === $tok->type:
                ++$this->pos;
                $key = $this->parsePipe();
                $this->expect(TokenType::RParen);
                $this->expect(TokenType::Colon);

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
                case TokenType::LParen:
                case TokenType::LBracket:
                case TokenType::LBrace:
                    ++$depth;

                    break;

                case TokenType::RParen:
                case TokenType::RBracket:
                case TokenType::RBrace:
                    if (0 === $depth) {
                        return false;
                    }

                    --$depth;

                    break;

                case TokenType::Colon:
                    if (0 === $depth) {
                        return true;
                    }

                    break;

                case TokenType::Comma:
                    if (0 === $depth) {
                        return false;
                    }

                    break;

                case TokenType::Eof:
                    return false;

                default:
            }
        }

        return false;
    }

    /**
     * An object value: postfix terms, optionally negated, joined with `|` (no other binary operators).
     */
    private function parseObjectValue(): Node
    {
        if (TokenType::Minus === $this->tokens[$this->pos]->type) {
            ++$this->pos;
            $value = new Negate($this->parseObjectValue());
        } else {
            $value = $this->parseExpr(self::LEVEL_ALT);
        }

        if (TokenType::Pipe === $this->tokens[$this->pos]->type) {
            ++$this->pos;

            return new Pipe($value, $this->parseObjectValue());
        }

        return $value;
    }

    /**
     * A string literal starting at StringStart. Without interpolation it is a Literal.
     */
    private function parseString(?string $format): Node
    {
        ++$this->pos;
        $parts        = [];
        $text         = '';
        $interpolated = false;
        while (true) {
            $tok = $this->tokens[$this->pos];
            switch ($tok->type) {
                case TokenType::StringFragment:
                    ++$this->pos;
                    $parts[] = $tok->text;
                    $text .= $tok->text;

                    break;

                case TokenType::InterpStart:
                    ++$this->pos;
                    $parts[]      = $this->parsePipe();
                    $interpolated = true;
                    $this->expect(TokenType::InterpEnd);

                    break;

                case TokenType::StringEnd:
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
            TokenType::KwDef, TokenType::KwIf, TokenType::KwThen, TokenType::KwElif, TokenType::KwElse,
            TokenType::KwEnd, TokenType::KwAs, TokenType::KwReduce, TokenType::KwForeach, TokenType::KwTry,
            TokenType::KwCatch, TokenType::KwLabel, TokenType::KwImport, TokenType::KwInclude,
            TokenType::KwModule, TokenType::KwAnd, TokenType::KwOr => true,
            default                                                => false,
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

    private function expect(TokenType $type): Token
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
        if (null === $expecting && 0 === $this->pos && TokenType::Eof !== $tok->type) {
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
