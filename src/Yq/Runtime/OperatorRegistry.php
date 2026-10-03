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
 * The lookup table the evaluator uses for Call and Binary nodes. Knows every operator of the reference;
 * more can be added (or built-ins replaced) with registerCall() and registerBinary().
 *
 * Operator classes are instantiated (and so autoloaded and compiled) on first use, through the CALLS and
 * BINARIES name tables below: loading all eleven call families up front costs a measurable share of the
 * startup of a trivial filter (benchmark yq:startup). A unit test keeps the tables equal to what each
 * class reports from names() and operators().
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
    /** @var array<string, class-string<CallOperatorInterface>> */
    public const array CALLS = [
        'select'          => SelectionCalls::class,
        'not'             => SelectionCalls::class,
        'has'             => SelectionCalls::class,
        'contains'        => SelectionCalls::class,
        'any'             => SelectionCalls::class,
        'all'             => SelectionCalls::class,
        'any_c'           => SelectionCalls::class,
        'all_c'           => SelectionCalls::class,
        'first'           => SelectionCalls::class,
        'last'            => SelectionCalls::class,
        'filter'          => SelectionCalls::class,
        'with'            => SelectionCalls::class,
        'empty'           => SelectionCalls::class,
        'parent'          => NavigationCalls::class,
        'parents'         => NavigationCalls::class,
        'root'            => NavigationCalls::class,
        'key'             => NavigationCalls::class,
        'is_key'          => NavigationCalls::class,
        'path'            => NavigationCalls::class,
        'getpath'         => NavigationCalls::class,
        'line'            => NavigationCalls::class,
        'column'          => NavigationCalls::class,
        'document_index'  => NavigationCalls::class,
        'di'              => NavigationCalls::class,
        'file_index'      => NavigationCalls::class,
        'fi'              => NavigationCalls::class,
        'filename'        => NavigationCalls::class,
        'split_doc'       => NavigationCalls::class,
        'splitDoc'        => NavigationCalls::class,
        'eval'            => NavigationCalls::class,
        'tag'             => MetaCalls::class,
        'type'            => MetaCalls::class,
        'kind'            => MetaCalls::class,
        'style'           => MetaCalls::class,
        'anchor'          => MetaCalls::class,
        'alias'           => MetaCalls::class,
        'head_comment'    => MetaCalls::class,
        'headComment'     => MetaCalls::class,
        'line_comment'    => MetaCalls::class,
        'lineComment'     => MetaCalls::class,
        'foot_comment'    => MetaCalls::class,
        'footComment'     => MetaCalls::class,
        'explode'         => MetaCalls::class,
        'sort_keys'       => MetaCalls::class,
        'length'          => CollectionCalls::class,
        'keys'            => CollectionCalls::class,
        'to_entries'      => CollectionCalls::class,
        'from_entries'    => CollectionCalls::class,
        'with_entries'    => CollectionCalls::class,
        'map'             => CollectionCalls::class,
        'map_values'      => CollectionCalls::class,
        'flatten'         => CollectionCalls::class,
        'add'             => CollectionCalls::class,
        'pivot'           => CollectionCalls::class,
        'array_to_map'    => CollectionCalls::class,
        'range'           => CollectionCalls::class,
        'sort'            => SortingCalls::class,
        'sort_by'         => SortingCalls::class,
        'group_by'        => SortingCalls::class,
        'unique'          => SortingCalls::class,
        'unique_by'       => SortingCalls::class,
        'min'             => SortingCalls::class,
        'max'             => SortingCalls::class,
        'reverse'         => SortingCalls::class,
        'shuffle'         => SortingCalls::class,
        'pick'            => StructureCalls::class,
        'omit'            => StructureCalls::class,
        'del'             => StructureCalls::class,
        'delete'          => StructureCalls::class,
        'delpaths'        => StructureCalls::class,
        'setpath'         => StructureCalls::class,
        'upcase'          => StringCalls::class,
        'ascii_upcase'    => StringCalls::class,
        'downcase'        => StringCalls::class,
        'ascii_downcase'  => StringCalls::class,
        'trim'            => StringCalls::class,
        'ltrimstr'        => StringCalls::class,
        'rtrimstr'        => StringCalls::class,
        'startswith'      => StringCalls::class,
        'endswith'        => StringCalls::class,
        'join'            => StringCalls::class,
        'split'           => StringCalls::class,
        'to_string'       => StringCalls::class,
        'tostring'        => StringCalls::class,
        'to_number'       => StringCalls::class,
        'tonumber'        => StringCalls::class,
        'to_bool'         => StringCalls::class,
        'test'            => RegexCalls::class,
        'match'           => RegexCalls::class,
        'capture'         => RegexCalls::class,
        'sub'             => RegexCalls::class,
        '@sh'             => FormatCalls::class,
        '@uri'            => FormatCalls::class,
        '@urid'           => FormatCalls::class,
        '@base64'         => FormatCalls::class,
        '@base64d'        => FormatCalls::class,
        '@base64url'      => FormatCalls::class,
        '@base64urld'     => FormatCalls::class,
        '@html'           => FormatCalls::class,
        '@csv'            => FormatCalls::class,
        '@tsv'            => FormatCalls::class,
        '@csvd'           => FormatCalls::class,
        '@tsvd'           => FormatCalls::class,
        '@json'           => FormatCalls::class,
        '@jsond'          => FormatCalls::class,
        'to_json'         => FormatCalls::class,
        'from_json'       => FormatCalls::class,
        '@yaml'           => FormatCalls::class,
        '@yamld'          => FormatCalls::class,
        'to_yaml'         => FormatCalls::class,
        'from_yaml'       => FormatCalls::class,
        '@props'          => FormatCalls::class,
        '@propsd'         => FormatCalls::class,
        'to_props'        => FormatCalls::class,
        'from_props'      => FormatCalls::class,
        '@xml'            => FormatCalls::class,
        '@xmld'           => FormatCalls::class,
        'to_xml'          => FormatCalls::class,
        'from_xml'        => FormatCalls::class,
        '@toml'           => FormatCalls::class,
        '@tomld'          => FormatCalls::class,
        'to_toml'         => FormatCalls::class,
        'from_toml'       => FormatCalls::class,
        '@hcl'            => FormatCalls::class,
        '@hcld'           => FormatCalls::class,
        'to_hcl'          => FormatCalls::class,
        'from_hcl'        => FormatCalls::class,
        '@lua'            => FormatCalls::class,
        '@luad'           => FormatCalls::class,
        'to_lua'          => FormatCalls::class,
        'from_lua'        => FormatCalls::class,
        '@shell'          => FormatCalls::class,
        '@shelld'         => FormatCalls::class,
        'to_shell'        => FormatCalls::class,
        'from_shell'      => FormatCalls::class,
        '@kyaml'          => FormatCalls::class,
        '@kyamld'         => FormatCalls::class,
        'to_kyaml'        => FormatCalls::class,
        'from_kyaml'      => FormatCalls::class,
        'to_csv'          => FormatCalls::class,
        'from_csv'        => FormatCalls::class,
        'to_tsv'          => FormatCalls::class,
        'from_tsv'        => FormatCalls::class,
        'now'             => DateCalls::class,
        'from_unix'       => DateCalls::class,
        'to_unix'         => DateCalls::class,
        'tz'              => DateCalls::class,
        'format_datetime' => DateCalls::class,
        'with_dtf'        => DateCalls::class,
        'env'             => EnvFileCalls::class,
        'strenv'          => EnvFileCalls::class,
        'envsubst'        => EnvFileCalls::class,
        'load'            => EnvFileCalls::class,
        'load_str'        => EnvFileCalls::class,
        'strload'         => EnvFileCalls::class,
        'system'          => EnvFileCalls::class,
    ];

    /** @var array<string, class-string<BinaryOperatorInterface>> */
    public const array BINARIES = [
        '|'   => PipeOperator::class,
        ','   => UnionOperator::class,
        '='   => AssignOperator::class,
        '|='  => AssignOperator::class,
        '+='  => AssignOperator::class,
        '-='  => AssignOperator::class,
        '*='  => AssignOperator::class,
        '/='  => AssignOperator::class,
        '%='  => AssignOperator::class,
        '//'  => AlternativeOperator::class,
        'and' => BooleanOperator::class,
        'or'  => BooleanOperator::class,
        '=='  => ComparisonOperator::class,
        '!='  => ComparisonOperator::class,
        '<'   => ComparisonOperator::class,
        '<='  => ComparisonOperator::class,
        '>'   => ComparisonOperator::class,
        '>='  => ComparisonOperator::class,
        '+'   => ArithmeticOperator::class,
        '-'   => ArithmeticOperator::class,
        '*'   => ArithmeticOperator::class,
        '/'   => ArithmeticOperator::class,
        '%'   => ArithmeticOperator::class,
    ];

    /** @var array<string, CallOperatorInterface> */
    private array $calls = [];

    /** @var array<string, BinaryOperatorInterface> */
    private array $binaries = [];

    public function call(string $name): ?CallOperatorInterface
    {
        return $this->calls[$name] ?? $this->loadCall($name);
    }

    public function binary(BinaryOperatorEnum $operator): ?BinaryOperatorInterface
    {
        return $this->binaries[$operator->value] ?? $this->loadBinary($operator->value);
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

    private function loadCall(string $name): ?CallOperatorInterface
    {
        $class = self::CALLS[$name] ?? null;
        if (null === $class) {
            return null;
        }

        // a name registered earlier (a replacement) keeps its operator
        $operator = new $class();
        foreach ($operator->names() as $own) {
            $this->calls[$own] ??= $operator;
        }

        return $this->calls[$name];
    }

    private function loadBinary(string $symbol): ?BinaryOperatorInterface
    {
        $class = self::BINARIES[$symbol] ?? null;
        if (null === $class) {
            return null;
        }

        $operator = new $class();
        foreach ($operator->operators() as $own) {
            $this->binaries[$own->value] ??= $operator;
        }

        return $this->binaries[$symbol];
    }
}
