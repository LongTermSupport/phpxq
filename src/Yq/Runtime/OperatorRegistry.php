<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\Operators\AlternativeOperator;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use LTS\PhpXq\Yq\Runtime\Operators\AssignOperator;
use LTS\PhpXq\Yq\Runtime\Operators\BooleanOperator;
use LTS\PhpXq\Yq\Runtime\Operators\CollectionCalls;
use LTS\PhpXq\Yq\Runtime\Operators\ComparisonOperator;
use LTS\PhpXq\Yq\Runtime\Operators\DateCalls;
use LTS\PhpXq\Yq\Runtime\Operators\EnvFileCalls;
use LTS\PhpXq\Yq\Runtime\Operators\FormatCalls;
use LTS\PhpXq\Yq\Runtime\Operators\MetaCalls;
use LTS\PhpXq\Yq\Runtime\Operators\NavigationCalls;
use LTS\PhpXq\Yq\Runtime\Operators\PipeOperator;
use LTS\PhpXq\Yq\Runtime\Operators\RegexCalls;
use LTS\PhpXq\Yq\Runtime\Operators\SelectionCalls;
use LTS\PhpXq\Yq\Runtime\Operators\SortingCalls;
use LTS\PhpXq\Yq\Runtime\Operators\StringCalls;
use LTS\PhpXq\Yq\Runtime\Operators\StructureCalls;
use LTS\PhpXq\Yq\Runtime\Operators\UnionOperator;

/**
 * The lookup table the evaluator uses for Call and Binary nodes. Constructed with every operator of the
 * reference registered; more can be added (or built-ins replaced) with registerCall() and registerBinary().
 *
 * Named operators by family (see the classes under Operators):
 *  - SelectionCalls:  select, not, has, contains, any, all, any_c, all_c, first, last, filter, with, empty
 *  - NavigationCalls: parent, parents, root, key, is_key, path, getpath, line, column, document_index (di),
 *                     file_index (fi), filename, split_doc, eval
 *  - MetaCalls:       tag, type, kind, style, anchor, alias, head_comment, line_comment, foot_comment, explode, sort_keys
 *  - CollectionCalls: length, keys, to_entries, from_entries, with_entries, map, map_values, flatten, add, pivot,
 *                     array_to_map, range
 *  - SortingCalls:    sort, sort_by, group_by, unique, unique_by, min, max, reverse, shuffle
 *  - StructureCalls:  pick, omit, del, delpaths, setpath
 *  - StringCalls:     upcase, downcase, trim, ltrimstr, rtrimstr, startswith, endswith, join, split, to_string,
 *                     to_number, to_bool
 *  - RegexCalls:      test, match, capture, sub
 *  - FormatCalls:     @json, @yaml, @csv, @tsv, @props, @xml, @base64, @base64d, @uri, @sh, ... to_X, from_X
 *  - DateCalls:       now, from_unix, to_unix, tz, format_datetime, with_dtf
 *  - EnvFileCalls:    env, strenv, envsubst, load, load_str, system
 * Binary operators: pipe, union, assignment family, alternative, and/or, comparison, arithmetic and merge.
 */
final class OperatorRegistry implements OperatorRegistryInterface
{
    /** @var array<string, CallOperatorInterface> */
    private array $calls = [];

    /** @var array<string, BinaryOperatorInterface> */
    private array $binaries = [];

    public function __construct()
    {
        foreach ([
            new SelectionCalls(),
            new NavigationCalls(),
            new MetaCalls(),
            new CollectionCalls(),
            new SortingCalls(),
            new StructureCalls(),
            new StringCalls(),
            new RegexCalls(),
            new FormatCalls(),
            new DateCalls(),
            new EnvFileCalls(),
        ] as $call) {
            $this->registerCall($call);
        }

        foreach ([
            new PipeOperator(),
            new UnionOperator(),
            new AssignOperator(),
            new AlternativeOperator(),
            new BooleanOperator(),
            new ComparisonOperator(),
            new ArithmeticOperator(),
        ] as $binary) {
            $this->registerBinary($binary);
        }
    }

    public function call(string $name): ?CallOperatorInterface
    {
        return $this->calls[$name] ?? null;
    }

    public function binary(BinaryOperatorEnum $operator): ?BinaryOperatorInterface
    {
        return $this->binaries[$operator->value] ?? null;
    }

    public function registerCall(CallOperatorInterface $operator): void
    {
        foreach ($operator->names() as $name) {
            $this->calls[$name] = $operator;
        }
    }

    public function registerBinary(BinaryOperatorInterface $operator): void
    {
        foreach ($operator->operators() as $symbol) {
            $this->binaries[$symbol->value] = $operator;
        }
    }
}
