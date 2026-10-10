<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

/**
 * Token kinds produced by the lexer. See {@see Token} for what each carries in its text.
 *
 * String literals are not one token: the lexer emits StringStart, then any number of StringFragment
 * (unescaped literal text) and interpolations (InterpStart, the tokens of the embedded expression,
 * InterpEnd), then StringEnd. The lexer tracks parenthesis depth so the `)` that closes an
 * interpolation becomes InterpEnd, not RParen. A format prefix such as `@base64 "..."` is a Format
 * token followed by the string tokens.
 *
 * @internal
 */
enum TokenTypeEnum: string
{
    // literals and names
    case Number         = 'number';         // text: the literal as written
    case Ident          = 'ident';          // text: name, may contain `::` namespaces
    case Field          = 'field';          // `.foo`; text: foo (also `.and`, `.if`: keywords are fields after a dot)
    case Variable       = 'variable';       // `$name`; text: name without `$` (`$__loc__` is Variable "__loc__")
    case Format         = 'format';         // `@name`; text: name without `@`
    case StringStart    = 'string-start';   // opening quote
    case StringFragment = 'string-fragment'; // text: the unescaped literal text
    case StringEnd      = 'string-end';     // closing quote
    case InterpStart    = 'interp-start';   // `\(` inside a string
    case InterpEnd      = 'interp-end';     // the `)` closing an interpolation

    // punctuation
    case Dot         = 'dot';          // .
    case DotDot      = 'dotdot';       // ..
    case Pipe        = 'pipe';         // |
    case Comma       = 'comma';        // ,
    case Colon       = 'colon';        // :
    case Semicolon   = 'semicolon';    // ;
    case LParen      = 'lparen';       // (
    case RParen      = 'rparen';       // )
    case LBracket    = 'lbracket';     // [
    case RBracket    = 'rbracket';     // ]
    case LBrace      = 'lbrace';       // {
    case RBrace      = 'rbrace';       // }
    case Question    = 'question';     // ?
    case DestructAlt = 'destruct-alt'; // ?//

    // operators
    case Assign        = 'assign';         // =
    case UpdateAssign  = 'update-assign';  // |=
    case PlusAssign    = 'plus-assign';    // +=
    case MinusAssign   = 'minus-assign';   // -=
    case StarAssign    = 'star-assign';    // *=
    case SlashAssign   = 'slash-assign';   // /=
    case PercentAssign = 'percent-assign'; // %=
    case AltAssign     = 'alt-assign';     // //=
    case Eq            = 'eq';             // ==
    case Neq           = 'neq';            // !=
    case Lt            = 'lt';             // <
    case Le            = 'le';             // <=
    case Gt            = 'gt';             // >
    case Ge            = 'ge';             // >=
    case Plus          = 'plus';           // +
    case Minus         = 'minus';          // -
    case Star          = 'star';           // *
    case Slash         = 'slash';          // /
    case Percent       = 'percent';        // %
    case Alt           = 'alt';            // //

    // keywords (text keeps the spelling so the parser can use a keyword as an object key)
    case KwDef     = 'def';
    case KwIf      = 'if';
    case KwThen    = 'then';
    case KwElif    = 'elif';
    case KwElse    = 'else';
    case KwEnd     = 'end';
    case KwAs      = 'as';
    case KwReduce  = 'reduce';
    case KwForeach = 'foreach';
    case KwTry     = 'try';
    case KwCatch   = 'catch';
    case KwLabel   = 'label';
    case KwImport  = 'import';
    case KwInclude = 'include';
    case KwModule  = 'module';
    case KwAnd     = 'and';
    case KwOr      = 'or';

    case Eof = 'eof';
}
