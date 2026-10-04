<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

/**
 * The builtins defined in jq itself. They inherit generator and path-expression semantics from the
 * evaluator for free; the hot ones are native instead (see the other classes of this namespace). The source
 * lives in PHP, not in a `.jq` resource, because the PHAR build packages `*.php` files only.
 *
 * @internal
 */
final readonly class Prelude
{
    public const string SOURCE = <<<'JQ'
        def halt_error: halt_error(5);
        def values: select(. != null);
        def nulls: select(. == null);
        def booleans: select(type == "boolean");
        def numbers: select(type == "number");
        def strings: select(type == "string");
        def arrays: select(type == "array");
        def objects: select(type == "object");
        def iterables: select(type | . == "array" or . == "object");
        def scalars: select(type | . != "array" and . != "object");
        def finites: select(isinfinite or isnan | not);
        def normals: select(isnormal);
        def isvalid(f): try (f | true) catch false;
        def map_values(f): .[] |= f;
        def recurse_down: recurse;
        def with_entries(f): to_entries | map(f) | from_entries;
        def del(f): delpaths([path(f)]);
        def add(f): reduce f as $x (null; . + $x);
        def any: any(.[]; .);
        def any(f): any(.[]; f);
        def all: all(.[]; .);
        def all(f): all(.[]; f);
        def in(xs): . as $x | xs | has($x);
        def inside(xs): . as $x | xs | contains($x);
        def combinations: if length == 0 then [] else .[0][] as $x | (.[1:] | combinations) as $w | [$x] + $w end;
        def combinations(n): . as $dot | [range(n)] | map($dot) | combinations;
        def walk(f): def w: if type == "object" then map_values(w) elif type == "array" then map(w) else . end | f; w;
        def first: .[0];
        def last: .[-1];
        def nth($n): .[$n];
        def nth($n; f): if $n < 0 then error("nth doesn't support negative indices") else first(skip($n; f)) end;
        def until(cond; update): def _until: if cond then . else (update | _until) end; _until;
        def while(cond; update): def _while: if cond then ., (update | _while) else empty end; _while;
        def sort_by(f): _sort_by_impl(map([f]));
        def group_by(f): _group_by_impl(map([f]));
        def unique_by(f): _unique_by_impl(map([f]));
        def min_by(f): _min_by_impl(map([f]));
        def max_by(f): _max_by_impl(map([f]));
        def paths(node_filter): . as $dot | paths | select(. as $p | $dot | getpath($p) | node_filter);
        def leaf_paths: paths(scalars);
        def pick(pathexps): . as $top | reduce path(pathexps) as $p (null; setpath($p; $top | getpath($p)));
        def indices($i):
          if type == "array" and ($i | type) == "array" then _array_indices($i)
          elif type == "array" then _array_indices([$i])
          elif type == "string" and ($i | type) == "string" then _strindices($i)
          else .[[$i]] end;
        def index($i): indices($i) | .[0];
        def rindex($i): indices($i) | .[-1:][0];
        def trimstr($x): ltrimstr($x) | rtrimstr($x);
        def truncate_stream(stream): . as $n | null | stream | . as $input | if (.[0] | length) > $n then setpath([0]; .[0][$n:]) else empty end;
        def INDEX(stream; idx_expr): reduce stream as $row ({}; .[$row | idx_expr | tostring] |= $row);
        def INDEX(idx_expr): INDEX(.[]; idx_expr);
        def JOIN($idx; idx_expr): [.[] | [., $idx[idx_expr]]];
        def JOIN($idx; stream; idx_expr): stream | [., $idx[idx_expr]];
        def JOIN($idx; stream; idx_expr; join_expr): stream | [., $idx[idx_expr]] | join_expr;
        def IN(s): any(s == .; .);
        def IN(src; s): any(src == s; .);
        JQ;

    private function __construct()
    {
    }

    /**
     * The `name/arity` of every def in {@see self::SOURCE}.
     *
     * @return list<string>
     */
    public static function signatures(): array
    {
        preg_match_all('/^def ([A-Za-z_][A-Za-z_0-9]*)(?:\(([^)]*)\))?:/m', self::SOURCE, $matches, \PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) {
            $arity = isset($match[2]) && '' !== $match[2] ? substr_count($match[2], ';') + 1 : 0;
            $out[] = $match[1] . '/' . $arity;
        }

        return $out;
    }
}
