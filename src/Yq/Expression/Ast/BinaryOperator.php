<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

/**
 * The infix operators of the expression language, valued with their surface token. Listed loosest-binding
 * first, which is the precedence order the parser applies (see architecture.md for associativity).
 */
enum BinaryOperator: string
{
    case Pipe = '|';

    case Union = ',';

    case Assign = '=';

    case Update = '|=';

    case AddAssign = '+=';

    case SubtractAssign = '-=';

    case MultiplyAssign = '*=';

    case DivideAssign = '/=';

    case ModuloAssign = '%=';

    case Alternative = '//';

    case Or = 'or';

    case And = 'and';

    case Equal = '==';

    case NotEqual = '!=';

    case Less = '<';

    case LessOrEqual = '<=';

    case Greater = '>';

    case GreaterOrEqual = '>=';

    case Add = '+';

    case Subtract = '-';

    /** `*`; the merge modifiers `+` `?` `d` `n` `c` ride in {@see Binary::$modifiers}. */
    case Multiply = '*';

    case Divide = '/';

    case Modulo = '%';
}
