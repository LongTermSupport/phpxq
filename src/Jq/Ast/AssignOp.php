<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Assignment operators with their source spelling as value.
 *
 * @api
 */
enum AssignOp: string
{
    case Set    = '=';
    case Update = '|=';
    case Add    = '+=';
    case Sub    = '-=';
    case Mul    = '*=';
    case Div    = '/=';
    case Mod    = '%=';
    case Alt    = '//=';
}
