<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Binary operators with their source spelling as value.
 *
 * @api
 */
enum BinaryOpEnum: string
{
    case Add = '+';
    case Sub = '-';
    case Mul = '*';
    case Div = '/';
    case Mod = '%';
    case Eq  = '==';
    case Neq = '!=';
    case Lt  = '<';
    case Le  = '<=';
    case Gt  = '>';
    case Ge  = '>=';

    /** short-circuiting `and` */
    case And = 'and';

    /** short-circuiting `or` */
    case Or = 'or';

    /** alternative operator `//` */
    case Alt = '//';
}
