<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * Lexical token kinds of the yq expression language.
 */
enum ExpressionTokenKindEnum
{
    /** An integer, float, hex or octal literal; `text` is the source text. */
    case Number;

    /** A quoted string literal; `text` is the decoded content, `raw` true when it may hold `\(...)` interpolation. */
    case String;

    /** A bare word: function names, `and`, `or`, `as`, `if`, `then`, `elif`, `else`, `end`, `reduce`, `true`, `false`, `null`, field names after a dot. */
    case Word;

    /** `$name`; `text` is the name without the `$`. */
    case Variable;

    /** Any symbolic operator: `|` `,` `=` `|=` `+=` `-=` `*=` `/=` `%=` `//` `==` `!=` `<` `<=` `>` `>=` `+` `-` `*` `/` `%` and the `*+` `*?` `*d` `*n` family. */
    case Operator;

    /** `.` */
    case Dot;

    /** `..` */
    case DotDot;

    /** `...` */
    case DotDotDot;

    /** `[` */
    case LeftBracket;

    /** `]` */
    case RightBracket;

    /** `(` */
    case LeftParen;

    /** `)` */
    case RightParen;

    /** `{` */
    case LeftBrace;

    /** `}` */
    case RightBrace;

    /** `;` */
    case Semicolon;

    /** `:` */
    case Colon;

    /** `?` */
    case Question;

    /** The last token of every expression; it carries no text. */
    case EndOfInput;
}
