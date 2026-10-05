<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Binary operators with their source spelling as value. `and` and `or` short-circuit; `//` is the alternative
 * operator.
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
    case And = 'and';
    case Or  = 'or';
    case Alt = '//';
}
