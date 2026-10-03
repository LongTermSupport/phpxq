<?php

declare(strict_types=1);

/**
 * Deterministic corpus generator for scripts/differential-jq.bash.
 *
 * Usage: php generate.php <seed> <outdir>
 *
 * Writes <outdir>/docs/NN.json (random documents sharing a loose schema) and <outdir>/cases.tsv
 * (one case per line: flags TAB filter TAB doc-file). Only behaviours stable between jq 1.6 and 1.8
 * are exercised.
 */

$seed   = (int)($argv[1] ?? 1);
$outDir = $argv[2] ?? 'untracked/scratch/diff';
mt_srand($seed);

const WORDS = ['alpha', 'Beta', 'gamma', 'delta', 'Épsilon', 'zeta eta', 'a,b', 'q"uote', "tab\there", 'x=y&z', 'foo.bar', '', 'ünï', '日本語', 'Hello World', 'abc123', '  pad  '];

function pick(array $a): mixed
{
    return $a[mt_rand(0, \count($a) - 1)];
}

function rndScalar(): mixed
{
    return match (mt_rand(0, 6)) {
        0       => null,
        1       => (bool)mt_rand(0, 1),
        2       => mt_rand(-50, 1000),
        3       => mt_rand(-1000, 1000) / 8,
        4, 5    => pick(WORDS),
        default => mt_rand(0, 3),
    };
}

function rndValue(int $depth): mixed
{
    if ($depth <= 0 || 0 === mt_rand(0, 2)) {
        return rndScalar();
    }
    if (mt_rand(0, 1)) {
        $n = mt_rand(0, 4);
        $a = [];
        for ($i = 0; $i < $n; ++$i) {
            $a[] = rndValue($depth - 1);
        }

        return $a;
    }
    $n = mt_rand(0, 4);
    $o = [];
    for ($i = 0; $i < $n; ++$i) {
        $o[pick(['a', 'b', 'c', 'name', 'id', 'k' . mt_rand(0, 9), 'Z', 'x y'])] = rndValue($depth - 1);
    }

    return [] === $o ? new stdClass() : $o;
}

function rndDoc(): mixed
{
    $users = [];
    $n     = mt_rand(0, 6);
    for ($i = 0; $i < $n; ++$i) {
        $tags = [];
        for ($j = mt_rand(0, 3); $j > 0; --$j) {
            $tags[] = pick(['red', 'green', 'blue', 'x', 'y']);
        }
        $users[] = [
            'id'     => $i + 1,
            'name'   => pick(WORDS),
            'age'    => mt_rand(0, 90),
            'tags'   => $tags,
            'active' => (bool)mt_rand(0, 1),
            'meta'   => mt_rand(0, 2) ? ['score' => mt_rand(0, 100) / 4, 'city' => pick(['Paris', 'rome', 'Oslo'])] : null,
        ];
    }
    $arr = [];
    for ($i = mt_rand(0, 8); $i > 0; --$i) {
        $arr[] = mt_rand(-20, 20);
    }
    $obj = [];
    for ($i = mt_rand(0, 5); $i > 0; --$i) {
        $obj['k' . mt_rand(0, 9)] = rndValue(2);
    }

    return [
        'users' => $users,
        'name'  => pick(WORDS),
        'n'     => mt_rand(-100, 100),
        's'     => pick(WORDS),
        'arr'   => $arr,
        'obj'   => [] === $obj ? new stdClass() : $obj,
        'any'   => rndValue(3),
        'text'  => pick(['The quick brown fox', 'a1b22c333', 'foo-bar_baz', 'one two  three', 'CamelCaseString', '2024-03-15T10:20:30Z']),
    ];
}

@mkdir($outDir . '/docs', 0o777, true);
$docs = [];
for ($i = 0; $i < 6; ++$i) {
    $file = \sprintf('%s/docs/%02d.json', $outDir, $i);
    file_put_contents($file, json_encode(rndDoc(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    $docs[] = $file;
}
file_put_contents($outDir . '/docs/multi.json', "1 2 3\n\"a\" [1,2] {\"a\":1} null true\n");
file_put_contents($outDir . '/docs/lines.txt', "alpha beta\ngamma\n\n  delta  \n");
file_put_contents($outDir . '/docs/csvish.txt', "a,b,c\n1,2,3\n");
file_put_contents($outDir . '/docs/bad.json', "{\"a\":1}\n{\"b\":\n");
file_put_contents($outDir . '/docs/bad2.json', "{\"a\":1} nope [1,2]\n");
file_put_contents($outDir . '/docs/bad3.json', "[1,2,}\n");
file_put_contents($outDir . '/docs/empty.json', '');
file_put_contents($outDir . '/docs/nums.json', "[0, -0, 1.0, 1.5, 100, 1e3, 1E-2, 12345678901234567890, 0.1, 3.14159, 1e17, 1e-7, 123456789012]\n");
file_put_contents($outDir . '/docs/unicode.json', "[\"\\u00e9\", \"\\ud83d\\ude00\", \"\\u0000x\", \"é\", \"\\t\\n\\r\\b\\f\\\\\\/\", \"\\u007f\", \"\\u001f\"]\n");
file_put_contents($outDir . '/docs/dupkeys.json', "{\"a\":1,\"b\":2,\"a\":3}\n");
file_put_contents($outDir . '/docs/order.json', "{\"z\":1,\"B\":2,\"a\":{\"y\":1,\"x\":[{\"d\":1,\"c\":2}]},\"A\":3}\n");

/** Filters needing the loose schema (run against the random docs). */
$schema = [
    '.', '.name', '.users', '.users[]', '.users[].name', '.users[0]', '.users[-1]', '.users[1:3]', '.users[:2]', '.users[-2:]',
    '.users | length', '.users | map(.age)', '.users | map(select(.active))', '.users | map(select(.age > 30) | .name)',
    '.users | map(.name | ascii_downcase)', '.users | map(.name | ascii_upcase)', '.users | group_by(.active)',
    '.users | group_by(.active) | map(length)', '.users | sort_by(.age)', '.users | sort_by(.name, .age)',
    '.users | sort_by(.age) | reverse | map(.id)', '.users | unique_by(.active) | map(.id)', '.users | min_by(.age)',
    '.users | max_by(.age)', '.users | map(.age) | add', '.users | map(.age) | add / length?', '[.users[] | select(.tags | index("red"))] | length',
    '.users | map({(.name): .age}) | add', '.users | map({id, name})', '.users | map(.tags) | flatten | unique', '.users | map(.tags | length)',
    '[.users[].tags[]] | group_by(.) | map({tag: .[0], n: length})', '.users | map(.meta.score?)', '.users | map(.meta // "none")',
    '.users | any(.age > 50)', '.users | all(.age >= 0)', '.users | map(.age) | min', '.users | map(.age) | max',
    '.users | map(select(.name | test("^[a-z]")))', '.users | map(.name | length)', '.users | first', '.users | last',
    '.users | map(.id) | join(",")', '.users | map(.name) | join("|")', '.users | map(.tags | join("+"))', '.users | to_entries | map(.key)',
    '.users | map(has("meta"))', '.users | map(.tags | contains(["red"]))', '.users | map(.tags | inside(["red","green","blue","x","y"]))',
    '.users | map(select(.tags | length > 1)) | length', '.users | map(.age | tostring)', '.users | map(.age | . * 2 | floor)',
    '.users | map(.age % 7)', '.users | map(.age / 3 | floor)', '.users | map(.age / 3 | round)', '.users | map(.age / 3 | ceil)',
    '.users | map(.age | sqrt | floor)', '.users | map(.age | pow(.; 2))', '.users | map(.age | log10? // 0 | floor)',
    '.users | indices({"id":1})', '.users | map(.id) | indices(2)', '.users | map(.id) | index(2)', '.users | map(.id) | rindex(2)',
    '.users | limit(2; .[])', '.users | first(.[])', '[limit(3; .users[])] | length', '[.users[] | .age] | sort', '[.users[] | .name] | sort',
    '.users | map(.name) | unique', '.users | map(.name) | sort | reverse', '.users | map(.active) | sort', '.users | transpose?',
    '.name', '.name | length', '.name | utf8bytelength', '.name | ascii_downcase', '.name | ascii_upcase', '.name | explode', '.name | explode | implode',
    '.name | @base64', '.name | @base64 | @base64d', '.name | @uri', '.name | @html', '.name | @sh', '.name | @json', '.name | @text', '.name | tojson',
    '.name | tojson | fromjson', '.name | ltrimstr("a")', '.name | rtrimstr("a")', '.name | startswith("a")', '.name | endswith("a")',
    '.name | split("")', '.name | split(" ")', '.name | split(",")', '.name | ascii_downcase | test("a")', '.name | test("A"; "i")',
    '.name | [match("[aeiou]"; "g").string]', '.name | sub("a"; "X")', '.name | gsub("a"; "X")', '.name | gsub("\\s+"; "_")', '.name | gsub("(?<c>[a-z])"; "\(.c)\(.c)")',
    '.name | [scan("[a-z]")]', '.name | capture("(?<first>.)(?<rest>.*)")', '.name | ltrimstr(1)', '.name | tostring', '.name | tojson | length',
    '.name | .[1:3]', '.name | .[:-1]', '.name | .[2:]', '.name | contains("a")', '.name | inside("alpha beta")', '.name | indices("a")',
    '.name | @csv "\(.)"', '.name | [., .] | @csv', '.name | [., .] | @tsv', '.name | [., 1, null, true] | @sh',
    '.n', '.n | tostring', '.n | tojson', '.n | -.', '.n | abs?', '.n | . + 1', '.n | . * 2', '.n | . / 3', '.n | . % 7', '.n | floor', '.n | sqrt?', '.n | fabs',
    '.n | [., 1] | max', '.n | [limit(5; range(.; .+10))]', '.n | [range(0; 10; 3)]', '.n | [range(5; 0; -2)]', '.n | tostring | tonumber', '.n | type',
    '.n | . == 0', '.n | if . > 0 then "pos" elif . < 0 then "neg" else "zero" end', '.n | [.[]?]', '.n | isnan', '.n | isinfinite', '.n | isnormal',
    '.s | length', '.s | test("o")', '.s | split(" ") | map(length)', '.s | ascii_downcase | split(" ") | map(.[0:1]) | join("")',
    '.arr', '.arr | length', '.arr | add', '.arr | sort', '.arr | reverse', '.arr | unique', '.arr | min', '.arr | max', '.arr | map(. * 2)', '.arr | map(select(. > 0))',
    '.arr | map(select(. % 2 == 0))', '.arr | group_by(. % 3)', '.arr | sort_by(-.)', '.arr | unique_by(. % 3)', '.arr | index(1)', '.arr | indices(1)',
    '.arr | contains([1])', '.arr | inside([1,2,3,4,5,6,7,8,9,10,-1,0])', '.arr | to_entries', '.arr | to_entries | map(select(.key % 2 == 0) | .value)',
    '.arr | [.[] | select(. > 5)]', '.arr | [limit(3; .[])]', '.arr | first', '.arr | last', '.arr | nth(1)', '.arr | [.[1:3][]]', '.arr | .[2:4] = ["x"]',
    '.arr | .[1:3] |= map(. + 100)', '.arr | del(.[0])', '.arr | del(.[1:3])', '.arr | del(.[] | select(. > 0))', '.arr | to_entries | from_entries?',
    '.arr | reduce .[] as $x (0; . + $x)', '.arr | reduce .[] as $x ([]; [$x] + .)', '.arr | [foreach .[] as $x (0; . + $x)]', '.arr | [foreach .[] as $x (0; . + $x; [$x, .])]',
    '.arr | [.[] as $x | $x * $x]', '.arr | . as [$a, $b] | [$a, $b]', '.arr | [paths]', '.arr | [paths(type == "number")]', '.arr | [leaf_paths]',
    '.arr | tojson', '.arr | @csv', '.arr | @tsv', '.arr | @sh', '.arr | @json', '.arr | @html', '.arr | map(tostring) | join(",")', '.arr | flatten', '.arr | combinations(2)?',
    '[.arr, .arr] | transpose', '.arr | [.[] | tostring] | sort', '.arr | map(. as $x | [$x, $x + 1])', '.arr | any', '.arr | all', '.arr | any(. > 10)', '.arr | all(. > -100)',
    '.arr | length as $n | add / ($n | if . == 0 then 1 else . end)', '.arr | [splits("a")?]', '.arr | walk(if type == "number" then . + 1 else . end)',
    '.arr | tostream | tojson', '[.arr | tostream] | fromstream(.[])', '.arr | getpath([0])', '.arr | setpath([0]; 99)', '.arr | delpaths([[0],[1]])', '.arr | paths | tojson',
    '.arr | input_line_number', '.arr | env | type', '.arr | $ENV | type', '.arr | splits("a")?', '.arr | ascii?', '.arr | limit(0; .[])', '.arr | until(length < 2; .[1:])',
    '.arr | [recurse(if length > 1 then .[1:] else empty end)] | length', '.arr | [.[] | if . > 0 then "p" else "n" end]', '.arr | .[0] as $first | map(. - $first)',
    '.arr | map(try (if . == 0 then error("zero") else 1 / . end) catch "err")', '.arr | map(. // "n")', '.arr | map(select(. != null))', '.arr | first(.[] | select(. > 3))',
    '.obj', '.obj | keys', '.obj | keys_unsorted', '.obj | values?', '.obj | length', '.obj | to_entries', '.obj | to_entries | map(.key)', '.obj | with_entries(.value |= tojson)',
    '.obj | with_entries(select(.value != null))', '.obj | from_entries?', '.obj | map_values(type)', '.obj | map(type)', '.obj | [.[]]', '.obj | del(.k1)', '.obj | has("k1")',
    '.obj | tojson', '.obj | tostring', '.obj | [paths]', '.obj | [paths(..)] | length', '.obj | [leaf_paths]', '.obj | walk(if type == "object" then del(.k1) else . end)',
    '.obj | add?', '.obj | any', '.obj | all', '.obj | to_entries | sort_by(.key) | map(.key)', '.obj | keys | map(ascii_upcase)', '.obj | . + {"new": 1}', '.obj | . * {"k1": {"z": 1}}',
    '.obj | with_entries(.key |= ascii_upcase)', '.obj | reduce to_entries[] as $e ({}; .[$e.key] = ($e.value | type))', '.obj | tostream | tojson', '.obj | .k1?', '.obj | .["k1"]?',
    '.obj | getpath(["k1"])', '.obj | setpath(["k1"]; 1)', '.obj | delpaths([["k1"]])', '.obj | to_entries | map("\(.key)=\(.value|tojson)") | join("&")',
    '.any', '.any | type', '.any | length?', '.any | tojson', '.any | tostring', '.any | [..]', '.any | [.. | scalars]', '.any | [.. | numbers]', '.any | [.. | strings]', '.any | [.. | arrays] | length',
    '.any | [.. | objects] | length', '.any | [paths]', '.any | [paths(type == "number")]', '.any | [leaf_paths]', '.any | walk(if type == "array" then sort else . end)',
    '.any | walk(if type == "number" then . + 1 else . end)', '.any | walk(if type == "string" then ascii_upcase else . end)', '.any | tostream | tojson', '[.any | tostream] | fromstream(.[])',
    '.any | [getpath(paths)] | length', '.any | reduce paths as $p (.; setpath($p; 0)) | tojson', '.any | [.. | select(type == "string")] | length', '.any | .. |= (if type == "number" then . + 1 else . end)',
    '.any | map(.)?', '.any | map_values(.)?', '.any | to_entries?', '.any | keys?', '.any | add?', '.any | min?', '.any | sort?', '.any | tojson | fromjson', '.any | [.[]?]', '.any | .a?', '.any | .[0]?',
    '.any | try error("x") catch .', '.any | try error catch .', '.any | try (.a.b.c) catch "caught"', '.any | .a.b.c?', '.any | ..?', '.any | [limit(5; ..)] | length', '.any | @json', '.any | @text',
    '.any | [.. | numbers] | add', '.any | splits("a")?', '.any | tojson | test("a")', '.any | isvalid(.a)?', '.any | getpath(["a","b"])', '.any | [paths] | map(map(tostring) | join("."))',
    '.any | del(.. | select(. == null))', '.any | del(.[]?)', '.any | to_entries?', '.any | with_entries(.)?', '.any | flatten?', '.any | tostring | length', '.any | ltrimstr("a")', '.any | splits(1)?',
    '.text | test("fox")', '.text | [match("\\d+"; "g") | .string | tonumber]', '.text | [scan("\\w+")]', '.text | [scan("[A-Z]")] | length', '.text | sub("(?<x>[a-z]+)"; "<\(.x)>")', '.text | gsub("[aeiou]"; "")',
    '.text | gsub("(?<d>\\d)"; "[\(.d)]")', '.text | split("-")', '.text | split("[-_]"; null)', '.text | split(" +"; "g")', '.text | ascii_downcase', '.text | capture("(?<y>\\d{4})-(?<m>\\d{2})-(?<d>\\d{2})")?',
    '.text | fromdateiso8601?', '.text | test("^the"; "i")', '.text | [match("(\\w)(\\w)"; "g")] | length', '.text | [match("(a)|(b)"; "g") | .captures | length]', '.text | sub("^\\s+"; "")', '.text | gsub("^\\s+|\\s+$"; "")',
    '.text | gsub(""; "-")', '.text | [match(""; "g")] | length', '.text | [match("o"; "g") | .offset]', '.text | ascii_downcase | gsub("[^a-z]"; "")', '.text | @uri', '.text | @base64', '.text | length', '.text | .[3:7]',
    '.text | test("\\bfox\\b")', '.text | [splits(" +")]', '.text | gsub("(?<a>.)(?<b>.)"; "\(.b)\(.a)")', '.text | sub("(?<x>o)"; "0"; "g")', '.text | ascii', '.text | tojson | fromjson | length', '.text | gsub("\\d"; "#")',
    '{a: .name, b: .n}', '{(.name): .n}', '{name, n}', '{"x": .arr}', '[.name, .n]', '[.arr[] | {v: .}]', '. as $d | $d.n', '. as {name: $n, n: $m} | [$n, $m]', '.. | numbers', '[..] | length', 'keys', 'keys_unsorted', 'to_entries | map(.key)',
    'with_entries(select(.key | test("^[a-n]")))', 'del(.users)', 'del(.users, .any)', 'delpaths([["users"], ["any"]])', 'to_entries | map(select(.value | type == "string")) | from_entries', 'map_values(type)', 'map(type)',
    'paths | length', '[paths] | length', '[paths(type == "string")] | length', '[leaf_paths] | length', 'tostream | select(length == 2) | .[0] | length', '[tostream] | length', 'input_filename', '$__loc__', '[.[] | type]', 'tojson | fromjson == .', 'tojson | length', 'tostring | length',
    '.name as $n | .n as $m | [$n, $m]', '.n as $n | .arr | map(. + $n)', 'if .n > 0 then .name else .s end', 'if .arr then "arr" else "no" end', 'if .nothing then 1 else 2 end', '.nothing // "default"', '.name // "d"', '.n // 5', '(.nothing, .n) // 9', 'first(.arr[], .n)',
    'label $out | foreach .arr[] as $x (0; . + $x; if . > 10 then ., break $out else . end)', '[.arr[] | select(. > 0)] | length', 'try error("boom") catch .', 'try (.name | error) catch .', '[.arr[] | try (if . > 5 then error("big") else . end) catch "E"]', '.arr | try first catch "x"',
    '[.[] | numbers]', 'def f: . + 1; .n | f', 'def f(x): x * 2; f(.n)', 'def fac: if . <= 1 then 1 else . * (. - 1 | fac) end; 10 | fac', 'def f($a; $b): $a + $b; f(1; 2)', '[recurse(if . < 3 then . + 1 else empty end)] | length', '[limit(5; repeat(1))]', '[1,2,3] | IN(2)', '[.arr[] | IN(1,2,3)]', '2 | IN(1,2,3)',
    '[.arr | splits("x")?]', 'ltrimstr("x")', '@json', '@text', '@base64', '@uri', 'tojson | @base64 | @base64d | fromjson | keys', '"\(.name) is \(.n)"', '@json "v=\(.arr)"', '@csv "\(.arr)"', '@sh "echo \(.name)"', '@uri "q=\(.name)"', '@html "<b>\(.name)</b>"', '@base64 "\(.name)"',
    'splits("a")?', 'env | type', '$ENV | type', 'now | type', 'input_line_number', '[.arr[] | . as $x | select($x > 0)]', '.users | map(.name) | @csv', '.users | map([.id, .name, .age]) | .[] | @csv', '.users | map([.id, .name]) | .[] | @tsv', '.users | map(.name) | @sh',
    '.users | map({name, age}) | map(select(.age > 18))', '.users | map(select(.name != "")) | length', '.users | [.[] | .tags[]?] | unique | length', '.users | map(.meta | select(. != null) | .score)', '.users | map(.meta.city // "?") | unique',
    '.users | map(.age) | sort | .[length / 2 | floor]', '.users | (map(.age) | add) as $t | map(.age / ($t | if . == 0 then 1 else . end))', '.users | map(.name | test("^[A-Z]"))', '.users | map(.name | ascii_downcase | gsub("[^a-z]"; ""))', '.users | map(select(.name | startswith("a") or startswith("g")))',
    '.users | INDEX(.id) | keys', '.users | map(.id) | map(. * .) | add', '.users | length as $l | map(.id / $l)', '.users | to_entries | map({i: .key, n: .value.name})', '.users | map(.tags | map(ascii_upcase))', '.users | [.[] | select(.active == true)] | map(.id)',
    '.users | map(.active | not)', '.users | map(.active and (.age > 18))', '.users | map(.active or (.age > 18))', '.users | map(if .active then .name else null end)', '.users | map(.age as $a | if $a < 18 then "minor" elif $a < 65 then "adult" else "senior" end)',
    '.users | group_by(.age > 30) | map(map(.id))', '.users | unique_by(.meta.city) | length', '.users | map(.id) | combinations?', '.users | [.[] | .id] | @json', '.users | map(.name) | map(select(length > 3))', '.users | min_by(.age).name?', '.users | map(.age) | [min, max, add]',
    '.users | sort_by(.active, -.age) | map(.id)', '.users | map(. + {extra: 1}) | map(.extra) | add', '.users | map(del(.tags, .meta))', '.users | map(with_entries(select(.key != "tags")))', '.users | map(to_entries | length) | unique', '.users | map(keys) | unique',
    '.users | map(.id |= . + 1) | map(.id)', '.users | map(.name |= ascii_upcase) | map(.name)', '.users | .[0].name = "changed" | .[0].name', '.users | .[].age += 1 | map(.age)', '.users | (.[] | select(.active) | .age) |= 0 | map(.age)', '.users | map(.tags |= sort)',
    '.users | map(.tags += ["new"]) | map(.tags | length)', '.users | map(.meta |= (. // {}))', '.users | map(.nonexistent)', '.users | map(.nonexistent // "d")', '.users | map(has("name"))', '.users | map(.name | type)', '.users | map(.age | type)', '.users | map(. | length)',
    '.users | tostream | select(length == 2) | .[1]', '.users | [paths] | length', '.users | [.. | .name? // empty] | length', '.users | [.[] | .meta | values]', '.users | add?', '.users | map(.tags) | add', '.users | .[0] | keys', '.users | .[0] | to_entries | map(.key)',
    '.users | reduce .[] as $u ({}; .[$u.name] += [$u.id])', '.users | reduce .[] as $u (0; . + $u.age)', '.users | [foreach .[] as $u (0; . + $u.age; .)]', '.users | [limit(2; .[] | .name)]', '.users | first(.[] | select(.age > 100)) // "none"', '.users | [.[] | select(.id | IN(1,3))] | length',
    '.users | map(select(.tags | any(. == "red")))  | length', '.users | map(select(.tags | all(. != "red"))) | length', '.users | map(.tags | index("red"))', '.users | map(.name | ascii_downcase | ltrimstr("a"))', '.users | map(.name | ltrimstr("a") | rtrimstr("a"))',
    '.users | map(.name | @base64)', '.users | map(.name | @uri)', '.users | map(.name | @sh)', '.users | map(.name | @html)', '.users | map(.name | tojson)', '.users | map([.name, .age] | @csv)', '.users | map([.name, .age] | @tsv)', '.users | map(.name | explode | length)',
];

$cases = [];
foreach ($schema as $filter) {
    foreach (array_rand($docs, 2) as $k) {
        $cases[] = ['-c', $filter, $docs[$k]];
    }
}

/** Flag and input-mode cases: [flags, filter, file]. */
$modes = [
    ['', '.', 'docs/00.json'], ['-c', '.', 'docs/01.json'], ['-r', '.name', 'docs/02.json'], ['-r', '.users[]?.name', 'docs/03.json'], ['-j', '.users[]?.name', 'docs/03.json'], ['-S', '.', 'docs/order.json'], ['-S -c', '.', 'docs/order.json'],
    ['-c', '.', 'docs/order.json'], ['--tab', '.', 'docs/order.json'], ['--indent 1', '.', 'docs/order.json'], ['--indent 0', '.', 'docs/order.json'], ['--indent 7', '.', 'docs/order.json'], ['--indent 3 -S', '.', 'docs/order.json'], ['-c', '.', 'docs/dupkeys.json'],
    ['-c', '.', 'docs/nums.json'], ['-c', '.[] | . + 0', 'docs/nums.json'], ['-c', 'map(. * 1)', 'docs/nums.json'], ['-c', 'map(tostring)', 'docs/nums.json'], ['-c', 'map(tojson)', 'docs/nums.json'], ['-c', 'map(floor)', 'docs/nums.json'], ['-c', 'map(. / 3)', 'docs/nums.json'],
    ['-c', 'map(type)', 'docs/nums.json'], ['-c', 'sort', 'docs/nums.json'], ['-c', 'add', 'docs/nums.json'], ['-c', 'unique', 'docs/nums.json'], ['-c', 'map(. == 1)', 'docs/nums.json'], ['-c', 'map(. * 1000000)', 'docs/nums.json'], ['-c', 'map(-.)', 'docs/nums.json'], ['-c', 'map(sqrt)', 'docs/nums.json'],
    ['-c', '.', 'docs/unicode.json'], ['-ac', '.', 'docs/unicode.json'], ['-r', '.[]', 'docs/unicode.json'], ['-c', 'map(length)', 'docs/unicode.json'], ['-c', 'map(utf8bytelength)', 'docs/unicode.json'], ['-c', 'map(explode)', 'docs/unicode.json'], ['-c', 'map(@json)', 'docs/unicode.json'],
    ['-c', 'map(ascii_downcase)', 'docs/unicode.json'], ['-c', 'map(@uri)', 'docs/unicode.json'], ['-c', 'map(@base64)', 'docs/unicode.json'], ['-c', 'map(tojson | fromjson)', 'docs/unicode.json'], ['-c', 'map(test("\\\\u0000")?)', 'docs/unicode.json'],
    ['-c', '.', 'docs/multi.json'], ['-c -n', '[inputs]', 'docs/multi.json'], ['-c -n', 'input', 'docs/multi.json'], ['-c -n', '[input, input]', 'docs/multi.json'], ['-c -n', 'reduce inputs as $x (0; . + 1)', 'docs/multi.json'], ['-c -s', '.', 'docs/multi.json'], ['-c', '[., input]', 'docs/multi.json'],
    ['-c', 'input_line_number', 'docs/multi.json'], ['-c -n', '[limit(2; inputs)]', 'docs/multi.json'], ['-c -n', 'first(inputs)', 'docs/multi.json'], ['-c -n', 'input | input', 'docs/multi.json'], ['-c', 'type', 'docs/multi.json'], ['-c -s', 'length', 'docs/multi.json'],
    ['-R', '.', 'docs/lines.txt'], ['-R -c', '[., length]', 'docs/lines.txt'], ['-Rs', '.', 'docs/lines.txt'], ['-Rn', '[inputs]', 'docs/lines.txt'], ['-Rn -c', '[inputs | select(length > 0)]', 'docs/lines.txt'], ['-Rc', 'split(" ")', 'docs/lines.txt'], ['-R -r', '. + "!"', 'docs/lines.txt'],
    ['-Rsc', 'split("\n")', 'docs/lines.txt'], ['-Rn -c', 'input', 'docs/lines.txt'], ['-R -c', 'ltrimstr(" ")', 'docs/lines.txt'], ['-R -c', 'ascii_upcase', 'docs/lines.txt'], ['-R -c', '[match("\\\\w+"; "g").string]', 'docs/lines.txt'], ['-R -c', 'test("a")', 'docs/lines.txt'],
    ['-R -c', 'split(",") | map(tonumber? // .)', 'docs/csvish.txt'], ['-R -c', '. / ","', 'docs/csvish.txt'], ['-R -r', 'split(",") | @tsv', 'docs/csvish.txt'], ['-R -r', 'split(",") | @csv', 'docs/csvish.txt'], ['-Rn -r', '[inputs | split(",")] | .[] | @csv', 'docs/csvish.txt'],
    ['-c', '.', 'docs/bad.json'], ['-c', '.', 'docs/bad2.json'], ['-c', '.', 'docs/bad3.json'], ['-c', '.', 'docs/empty.json'], ['-c -s', '.', 'docs/empty.json'], ['-c -n', '[inputs]', 'docs/empty.json'], ['-c -n', 'input', 'docs/empty.json'], ['-e', '.', 'docs/empty.json'],
    ['-c', '.', 'docs/missing.json'], ['-c', '.', 'docs/00.json docs/missing.json'], ['-c', 'input_filename', 'docs/00.json docs/01.json'], ['-c', '.n', 'docs/00.json docs/01.json docs/02.json'], ['-c -s', 'map(.n)', 'docs/00.json docs/01.json docs/02.json'],
    ['-e', '.n', 'docs/00.json'], ['-e', 'null', 'docs/00.json'], ['-e', 'false', 'docs/00.json'], ['-e', 'empty', 'docs/00.json'], ['-e', '1, null', 'docs/00.json'], ['-e', 'null, 1', 'docs/00.json'], ['-e', '.users[0].active', 'docs/00.json'], ['-e', 'error("x")', 'docs/00.json'], ['-e', '.', 'docs/multi.json'],
    ['', 'error("custom")', 'docs/00.json'], ['', 'error({a:1})', 'docs/00.json'], ['', 'error(null)', 'docs/00.json'], ['', '.n | error', 'docs/00.json'], ['-c', '.a.b', 'docs/multi.json'], ['', '1/0', 'docs/00.json'], ['', '[1,2] | .[] | . / 0', 'docs/00.json'], ['', '{} | .a.b.c = 1', 'docs/00.json'],
    ['', '"a" + 1', 'docs/00.json'], ['', '[] | implode', 'docs/00.json'], ['', 'null | keys', 'docs/00.json'], ['', '{} | has(1)', 'docs/00.json'], ['', '[] | has("a")', 'docs/00.json'], ['', '"abc" | .[0]', 'docs/00.json'], ['', '{} | .[0]', 'docs/00.json'], ['', '[] | .["a"]', 'docs/00.json'], ['', '1 | .[]', 'docs/00.json'],
    ['', '"x" | tonumber', 'docs/00.json'], ['', '[1] | join(",") | tonumber', 'docs/00.json'], ['', '{} | tonumber', 'docs/00.json'], ['', '[[1]] | join(",")', 'docs/00.json'], ['', '1 | ltrimstr("a")', 'docs/00.json'], ['', '"a" | test(1)', 'docs/00.json'], ['', '"a" | test("(")', 'docs/00.json'],
    ['', '. | fromjson', 'docs/00.json'], ['', '"{" | fromjson', 'docs/00.json'], ['', '"[1,2" | fromjson', 'docs/00.json'], ['', '{} - 1', 'docs/00.json'], ['', '[] - 1', 'docs/00.json'], ['', '{} * 2', 'docs/00.json'], ['', '"abc" * 0', 'docs/00.json'], ['', 'null | explode', 'docs/00.json'],
    ['', '[1,2,3] | .[1.5]', 'docs/00.json'], ['', '[1,2,3] | .[-5]', 'docs/00.json'], ['', 'null | .[1:2]', 'docs/00.json'], ['', '[1] | .[null:1]', 'docs/00.json'], ['', 'limit(-1; 1,2)', 'docs/00.json'], ['', 'try error catch .', 'docs/00.json'], ['', 'try error("\\(1)") catch .', 'docs/00.json'],
    ['', 'error', 'docs/00.json'], ['', '.users[] | error', 'docs/00.json'], ['', '.[] | error', 'docs/multi.json'], ['', '$x', 'docs/00.json'], ['', 'foo', 'docs/00.json'], ['', '.[', 'docs/00.json'], ['', '{', 'docs/00.json'], ['', '1 +', 'docs/00.json'], ['', '}', 'docs/00.json'], ['', 'if 1 then 2', 'docs/00.json'],
    ['', 'foo(1)', 'docs/00.json'], ['', '"abc', 'docs/00.json'], ['', '.a.[0]', 'docs/00.json'], ['', '. as [$a] | $b', 'docs/00.json'], ['', 'reduce . as $x', 'docs/00.json'], ['', '@foo', 'docs/00.json'], ['', '"\\(1" ', 'docs/00.json'], ['', '1 as $x | 2 as $x | $x', 'docs/00.json'],
    ['--arg a 1 -c', '[$a, $ARGS.named]', 'docs/00.json'], ['--argjson a {"x":[1,2]} -c', '[$a, $a.x[0]]', 'docs/00.json'], ['--argjson a {bad -c', '$a', 'docs/00.json'], ['--arg a -c', '$a', 'docs/00.json'], ['-n --arg a 1 --arg b 2 -c', '$ARGS', ''],
    ['-n -c --args', '$ARGS.positional', '--  a b c'], ['-n -c --jsonargs', '$ARGS.positional', '--  1 {"a":2} null'], ['-n -c', '$ENV | type', ''], ['-n -c', 'env | type', ''], ['-n -c', '$__prog_args?', ''], ['-n', '"a" , 1 , null', ''], ['-n -r', '"a\\tb", "c"', ''], ['-n -j', '"a", 1, "b"', ''],
    ['-n -a', '"é日本語\\ud83d\\ude00"', ''], ['-n', '"é日本語"', ''], ['-n -c', '[1,[2,[3,{"a":[]}]]]', ''], ['-n', '[1,[2,[3,{"a":[]}]]]', ''], ['-n', '{"a":{"b":{}}, "c":[]}', ''], ['-n -S', '{"b":1,"a":2}', ''], ['-n --tab', '[1,{"a":2}]', ''], ['-n --indent 1', '[1,{"a":2}]', ''],
    ['-n -c', '[limit(3; range(10))]', ''], ['-n -c', '[range(5)] | map(. * .)', ''], ['-n -c', '"abc" | [match("(?<x>b)").captures[0].name]', ''], ['-n -c', '[1,2] | tojson', ''], ['-n -c', '1e1000', ''], ['-n -c', '-1e1000', ''], ['-n -c', '[nan] | tojson', ''], ['-n -c', 'nan | isnan', ''],
    ['-n -c', '[nan, 1] | sort', ''], ['-n -c', 'infinite', ''], ['-n -c', '-infinite', ''], ['-n -c', '[infinite, -infinite, nan] | map(tostring)', ''], ['-n -c', '[.1, 1.0, 1.10, 100000000000000000000, 1e5, 1e-5, 0.0001, 1.5e300, 5e-324]', ''],
    ['-n -c', '[1, 1.0, 1.5] | map(tojson)', ''], ['-n -c', '3 | . / 2', ''], ['-n -c', '[3, 3.0, 3.5] | map(floor)', ''], ['-n -c', '9007199254740993', ''], ['-n -c', '9007199254740993 | . + 0', ''], ['-n -c', '[9007199254740993] | tojson', ''], ['-n -c', '0.1 + 0.2', ''], ['-n -c', '1 / 3', ''],
    ['-n -c', '[pow(2; 10), pow(2; 0.5), log2(8), exp10(2)?, exp2(3)]', ''], ['-n -c', '[floor, sqrt, ceil] | length', ''], ['-n -c', '5 % 3, -5 % 3, 5 % -3, 5.9 % 3.1', ''], ['-n -c', '[1,2] | .[0] / .[1]', ''], ['-n -c', '1 % 0', ''],
    ['-n -c', '"a,b, c" | split(", ")', ''], ['-n -c', '"abc" | ascii_downcase, ascii_upcase', ''], ['-n -c', '[1,2,3] | tojson | fromjson', ''], ['-n -c', '"\\u00e9" | @uri', ''], ['-n -c', '"a b+c" | @uri', ''], ['-n -c', '"<&>\'\\"" | @html', ''], ['-n -r', '["a", "b c", "d\\te", "f\\"g", 1, null, true] | @csv', ''],
    ['-n -r', '["a", "b c", "d\\te", "f\\\\g", 1, null, true] | @tsv', ''], ['-n -r', '["a", "b\'c", 1] | @sh', ''], ['-n -r', '"a\'b" | @sh', ''], ['-n -r', '"hello" | @base64', ''], ['-n -r', '"aGVsbG8=" | @base64d', ''], ['-n -r', '"aGVsbG8" | @base64d', ''], ['-n -r', '"é" | @base64', ''], ['-n -c', '[1,[2]] | @csv?', ''],
    ['-n -c', '"2015-03-05T23:51:47Z" | fromdate', ''], ['-n -c', '1425599507 | todate', ''], ['-n -c', '1425599507 | gmtime', ''], ['-n -c', '1425599507 | gmtime | mktime', ''], ['-n -c', '1425599507 | strftime("%Y-%m-%dT%H:%M:%SZ")', ''], ['-n -c', '"2015-03-05T23:51:47Z" | strptime("%Y-%m-%dT%H:%M:%SZ")', ''],
    ['-n -c', '[splits("a, b";", ")]', ''], ['-n -c', '"abc" | sub("(?<x>b)"; "[\\(.x)]")', ''], ['-n -c', '"aXbxc" | [splits("x"; "i")]', ''], ['-n -c', '"abc" | test("B"; "ix")', ''], ['-n -c', '"a.b" | split(".")', ''], ['-n -c', '"abab" | [match("ab"; "g").offset]', ''], ['-n -c', '"aAbB" | gsub("[a-z]"; "")', ''],
    ['-n -c', '"test" | ltrimstr("te") | rtrimstr("t")', ''], ['-n -c', '"x" | ascii_downcase | explode | map(. + 1) | implode', ''], ['-n -c', '[.[]?]', ''], ['-n -c', '[1,2,3] | IN([1,2,3])', ''], ['-n -c', '{} | .a += 1', ''], ['-n -c', '{} | .a //= 3', ''], ['-n -c', '{"a":1} | .a -= 1 | .a *= 5 | .a /= 2', ''],
    ['-n -c', '[1,2,3] | .[1:] = ["x","y","z"]', ''], ['-n -c', '[1,2,3] | to_entries', ''], ['-n -c', '[[1,2],[3,4]] | transpose', ''], ['-n -c', '[[1,2],[3,4]] | flatten(0)', ''], ['-n -c', '[1,[2,[3]]] | flatten(1)', ''], ['-n -c', '[1,[2,[3]]] | flatten(-1)', ''],
    ['-n -c', '{"a":[1,2]} | tostream', ''], ['-n -c', '[1,[2]] | getpath([1,0])', ''], ['-n -c', '[1,[2]] | paths', ''], ['-n -c', '{"a":1} | to_entries', ''], ['-n -c', '[{"name":"a","value":1},{"k":"b","v":2}] | from_entries', ''], ['-n -c', '{"a":1,"b":2} | with_entries(.value += 1)', ''],
    ['-n -c', '[3,1,2] | sort_by(-.)', ''], ['-n -c', '[{"a":1,"b":2},{"a":1,"b":1}] | sort_by(.a)', ''], ['-n -c', '[{"a":1,"b":2},{"a":1,"b":1}] | group_by(.a)', ''], ['-n -c', '[{"a":1,"b":2},{"a":1,"b":1}] | unique_by(.a)', ''], ['-n -c', '[null, true, false, 0, "a", [], {}] | sort', ''],
    ['-n -c', '[{"b":1},{"a":2},{"a":1,"b":0}] | sort', ''], ['-n -c', '[[2],[1,2],[1]] | sort', ''], ['-n -c', '[null, 1] | map(. // "d")', ''], ['-n -c', '[false, null, 0] | map(not)', ''], ['-n -c', '"abc" | ascii', ''], ['-n -c', '[1,2] | combinations', ''], ['-n -c', '[[1,2],[3,4]] | [combinations]', ''],
    ['-n -c', '[splits("a";"b";"c")]', ''], ['-n -c', 'getpath(["a","b"])', ''], ['-n -c', '{"a":{"b":1}} | [paths(type == "number")]', ''], ['-n -c', '{"a":[1,{"b":2}]} | [leaf_paths]', ''], ['-n -c', '[1,2,3] | del(.[0,2])', ''], ['-n -c', '{"a":1,"b":2} | del(.a, .b)', ''],
    ['-n -c', '{"a":1} | has("a"), has("b")', ''], ['-n -c', '[1,2] | has(0), has(5)', ''], ['-n -c', '{"a":1} | in({"a":2})?', ''], ['-n -c', '"a" | in({"a":2})', ''], ['-n -c', '[1,2,3] | contains([2])', ''], ['-n -c', '"foobar" | contains("bar")', ''], ['-n -c', '{"a":{"b":1}} | contains({"a":{}})', ''],
    ['-n -c', 'splits', ''], ['-n -c', '[range(3)] | map(select(. > 0)) | first', ''], ['-n -c', '[range(3)] | first, last, nth(1)', ''], ['-n -c', 'nth(2; range(10))', ''], ['-n -c', '[limit(3; repeat("x"))]', ''], ['-n -c', '[range(10)] | .[2:4]', ''], ['-n -c', '[range(10)] | .[-3:]', ''],
    ['-n -c', 'ltrimstr("a")', ''], ['-n -c', '"a" | ascii_downcase | @json', ''], ['-n -c', '@json "x\\(1+1)y"', ''], ['-n -c', '"\\(1;2)"', ''], ['-n -c', '[.[] | select(.a)]', ''], ['-n -c', 'reduce range(5) as $i ([]; . + [$i * 2])', ''], ['-n -c', '[foreach range(5) as $i (0; . + $i; select(. % 2 == 0))]', ''],
    ['-n -c', '[range(5)] | map(select(. != 2)) | length', ''], ['-n -c', 'try (1, error("x"), 3) catch .', ''], ['-n -c', '[.[]?] | length', ''], ['-n -c', '[true, false] | map(tostring)', ''], ['-n -c', '{"a":null} | .a // "x"', ''], ['-n -c', 'def f(g): [g, g]; f(1, 2)', ''], ['-n -c', 'def f: def g: 3; g * 2; f', ''],
    ['-n -c', '[1,2,3] as [$a] | $a', ''], ['-n -c', '{"a":1,"b":[2]} as {a: $x, b: [$y]} | [$x, $y]', ''], ['-n -c', '[[1,2],[3,4]] | .[] as [$a, $b] | $a + $b', ''], ['-n -c', '. as [$a] ?// $a | $a', ''], ['-n -c', 'input_filename', ''], ['-n -c', '$__loc__', ''],
    ['-n -c', '"x" | ltrimstr("x"), rtrimstr("x")', ''], ['-n -c', 'ascii_downcase?', ''], ['-n -c', '[1,2] | map(tostring) | join("-")', ''], ['-n -c', '[1,null,"a",true] | join(",")', ''], ['-n -c', '[] | join(",")', ''], ['-n -c', '"abc" | split("b"; null)', ''], ['-n -c', 'tojson', ''],
    ['-n -c', '[.[]?, 1]', ''], ['-n -c', '{} | .["a","b"] = 1', ''], ['-n -c', '[1,2,3] | (.[] | select(. > 1)) = 0', ''], ['-n -c', '[1,2,3] | map(select(. > 1) |= 0)?', ''], ['-n -c', '{"a":[1,2]} | .a[1:] |= [9,9]', ''], ['-n -c', '{"a":1} | with_entries(.key |= "x" + .)', ''],
    ['-n -c', '[3,[1]] | tostring', ''], ['-n -c', '[1,2,3] | indices(2), index(2), rindex(2)', ''], ['-n -c', '"a,b,a" | indices("a")', ''], ['-n -c', '[0,1,2,1] | indices([1])', ''], ['-n -c', '"" | indices("")', ''], ['-n -c', '[] | add', ''], ['-n -c', '["a","b"] | add', ''], ['-n -c', '[[1],[2]] | add', ''], ['-n -c', '[{"a":1},{"b":2}] | add', ''],
    ['-n -c', '[1,2,3] | min_by(-.), max_by(-.)', ''], ['-n -c', '[] | min, max', ''], ['-n -c', '[1,2,3] | any(. > 2), all(. > 2)', ''], ['-n -c', '[] | any, all', ''], ['-n -c', '[range(5)] | any(. == 3)', ''], ['-n -c', '"abc" | tojson | tojson', ''], ['-n -c', '[1,2,3] | @json', ''], ['-n -c', 'walk(.)', ''],
    ['-n -c', '[[1,[2]],3] | walk(if type == "array" then reverse else . end)', ''], ['-n -c', '{"b":1,"a":[3,2]} | walk(if type == "array" then sort else . end)', ''], ['-n -c', '[1,2] | tostream', ''], ['-n -c', '[fromstream(([1,[2]] | tostream))]', ''], ['-n -c', '[1,[2]] | [tostream] | fromstream(.[])', ''],
    ['-n -c', '[limit(3; 1, 2, 3, 4)]', ''], ['-n -c', 'first(range(10; 0; -3))', ''], ['-n -c', '[until(. > 100; . * 2)]?', ''], ['-n -c', '1 | until(. > 100; . * 2)', ''], ['-n -c', '[1 | while(. < 100; . * 2)]', ''], ['-n -c', '[1 | recurse(if . < 20 then . * 3 else empty end)]', ''], ['-n -c', '[2 | recurse(. * .; . < 100)]', ''],
    ['-n -c', '{"a":[{"b":1},{"b":2}]} | [.. | .b? | numbers]', ''], ['-n -c', '{"a":[{"b":1},{"b":2}]} | .a[].b', ''], ['-n -c', '{"a":[{"b":1},{"b":2}]} | [.a[] | .b] | add', ''], ['-n -c', '{"a":[{"b":1},{"b":2}]} | .a |= map(.b)', ''],
    ['-n -c', 'ltrimstr', ''], ['-n -c', '1 as $x | [$x, $x + 1]', ''], ['-n -c', '[.] | length', ''], ['-n -c', 'null | length', ''], ['-n -c', '-5 | length', ''], ['-n -c', 'true | length', ''], ['-n -c', '"é" | length', ''], ['-n -c', '[null] | length', ''], ['-n -c', '{"a":null} | length', ''],
    ['-n -c', '[splits("a")]?', ''], ['-n -c', '"1,2" | split(",") | map(tonumber)', ''], ['-n -c', '" 1 " | tonumber?', ''], ['-n -c', '"0x10" | tonumber?', ''], ['-n -c', '"1e2" | tonumber', ''], ['-n -c', '"-0" | tonumber', ''], ['-n -c', '"nan" | tonumber?', ''], ['-n -c', '[1,2] | map(tostring | tonumber)', ''],
    ['-n -c', '[true, null] | map(tojson)', ''], ['-n -c', '"\\u0000" | length', ''], ['-n -c', '"\\u0000" | @json', ''], ['-n -c', '"\\ud800" | length', ''], ['-n -c', '"\\ud83d\\ude00" | length', ''], ['-n -c', '"\\ud83d\\ude00" | utf8bytelength', ''], ['-n -c', '"\\ud83d\\ude00" | explode', ''], ['-n -c', '[128512] | implode', ''],
    ['-n -c', '[55357, 56832] | implode', ''], ['-n -c', '"a" | ascii_downcase | ascii_upcase | @text', ''], ['-n -c', '"é" | ascii_upcase', ''], ['-n -c', '"Σ" | ascii_downcase', ''], ['-n -c', '[limit(1; error("x"))]?', ''], ['-n -c', 'try ("a" | error) catch (. + "b")', ''],
    ['-n -c', '[.[]?] | tojson', ''], ['-n -c', '[1,2,3] | .[1:] | length', ''], ['-n -c', '"abc" | .[1:]', ''], ['-n -c', '"aéb" | .[1:2]', ''], ['-n -c', '"abc" | .[-1:]', ''], ['-n -c', 'ltrimstr(null)', ''], ['-n -c', '[1,2,3] | .[null]?', ''], ['-n -c', '{"a":1} | .["a"]', ''],
    ['-n -c', '"abc" | test("a.c"; "x")', ''], ['-n -c', '"a\\nb" | test("a.b"; "s")', ''], ['-n -c', '"a\\nb" | [match("^b"; "g")] | length', ''], ['-n -c', '"aaa" | [match("a*?"; "g")] | length', ''], ['-n -c', '"abc" | [match("(?<n>x)?b"; "g")] | .[0].captures', ''],
    ['-n -c', '"foo bar" | capture("(?<a>\\w+) (?<b>\\w+)")', ''], ['-n -c', '"foo bar" | [scan("(\\w)(\\w)")]', ''], ['-n -c', '"foo bar" | [scan("o+")]', ''], ['-n -c', '"foo" | sub("o"; "0"; "g")', ''], ['-n -c', '"foo" | sub("(?<x>o)"; "\\(.x)\\(.x)")', ''], ['-n -c', '"abc" | sub("$"; "!")', ''], ['-n -c', '"abc" | gsub("^"; "!")', ''],
    ['-n -c', '"abc" | [match("."; "g")] | map(.length)', ''], ['-n -c', '"aXbX" | ascii_downcase | split("x")', ''], ['-n -c', '"abc" | split("")', ''], ['-n -c', '"" | split("")', ''], ['-n -c', '"" | split(",")', ''], ['-n -c', '"a" | split("a")', ''], ['-n -c', '"abc" | startswith("")', ''],
    ['-n -c', '"abc" | startswith(1)', ''], ['-n -c', '"abc" | endswith("bc")', ''], ['-n -c', '"a" * 3', ''], ['-n -c', '"a" * 0.5', ''], ['-n -c', '"a" * -1', ''], ['-n -c', '"abc" / "b"', ''], ['-n -c', '[1,2,3] - [2]', ''], ['-n -c', '{"a":{"b":1}} * {"a":{"c":2}}', ''], ['-n -c', '{"a":1} + {"a":2}', ''], ['-n -c', 'null + 1, 1 + null, null + null', ''],
    ['-n -c', '[1] + null', ''], ['-n -c', '"a" + null', ''], ['-n -c', '1 - null', ''], ['-n -c', '[] | first?', ''], ['-n -c', '[] | first(.[])?', ''], ['-n -c', '[range(3)] | last(.[])', ''], ['-n -c', '[limit(0; 1)]', ''], ['-n -c', 'isempty(empty), isempty(1)', ''], ['-n -c', '[range(10)] | .[2:5] | .[1]', ''],
    ['-n -c', 'splits("a")', ''], ['-n -c', 'getpath(["a"]) = 1', ''], ['-n -c', 'paths', ''], ['-n -c', '[paths]', ''], ['-n -c', 'input', ''], ['-n -c', 'inputs', ''], ['-n -c', 'debug', ''], ['-n -c', '1 | debug', ''], ['-n -c', '1 | debug("m")', ''], ['-n -c', '"x" | stderr', ''], ['-n -c', '[1] | halt_error', ''], ['-n -c', '"bye\\n" | halt_error', ''], ['-n -c', '"bye" | halt_error(3)', ''], ['-n -c', '{"a":1} | halt_error', ''], ['-n -c', 'halt', ''],
    ['-n -c', 'input_line_number', ''], ['-n -c', '$ENV.PATH | type', ''], ['-n -c', 'ltrimstr("a") | halt', ''], ['-n -c', 'limit(1; 1, error("no"))', ''], ['-n -c', 'first(1, error("no"))', ''], ['-n -c', '[.[]?] | halt_error', ''], ['-n -c', 'error("a\\nb")', ''], ['-n -c', 'error([1])', ''], ['-n -c', 'error(true)', ''], ['-n -c', '{} | error', ''],
    ['--seq -c', '.', 'docs/multi.json'], ['--seq -n -c', '[1,2]', ''], ['--stream -c', '.', 'docs/00.json'], ['--stream -c', '.', 'docs/multi.json'], ['--stream -c -n', '[inputs]', 'docs/multi.json'], ['--stream -c -s', '.', 'docs/multi.json'], ['--stream -c', '.', 'docs/order.json'], ['--stream -c', '.', 'docs/unicode.json'], ['--stream -c', '.', 'docs/bad.json'],
    ['-c --stream -n', 'fromstream(inputs)', 'docs/order.json'], ['-c --stream -n', 'fromstream(1|truncate_stream(inputs))', 'docs/order.json'], ['-c', 'tostream', 'docs/order.json'], ['-c', '[tostream] | fromstream(.[])', 'docs/order.json'], ['-c', '[.[]?]', 'docs/multi.json'],
    ['-C', '.', 'docs/order.json'], ['-C -c', '.', 'docs/order.json'], ['-M', '.', 'docs/order.json'], ['-C', '.', 'docs/unicode.json'], ['-C', '.', 'docs/nums.json'], ['--color-output', '[]', 'docs/order.json'], ['-C -c', '[{}, [], "a", 1, null, true, false]', 'docs/order.json'],
    ['-n -r', '"a\\u0000b"', ''], ['-n -j', '"a\\u0000b"', ''], ['-n --raw-output0', '"a", "b"', ''], ['-n -a -c', '"\\u007f\\u0080\\u00ff\\u0100\\ud83d\\ude00"', ''], ['-n -c', '"\\u001f\\u007f" | @json', ''], ['-n -r', '@json "\\("\\u001f")"', ''],
    ['--raw-input --slurp', '.', 'docs/lines.txt'], ['-nR', 'input', 'docs/lines.txt'], ['-nR', '[inputs] | length', 'docs/lines.txt'], ['-sR -c', 'split("\\n") | length', 'docs/lines.txt'], ['-c --slurp', 'map(type)', 'docs/multi.json'], ['--slurp -R -c', 'length', 'docs/csvish.txt'], ['-s -n -c', '[inputs]', 'docs/multi.json'], ['-s -n -c', 'input', 'docs/multi.json'],
    ['-f docs/prog.jq -c', '', 'docs/00.json'], ['--from-file docs/prog.jq -c', '', 'docs/00.json'], ['-c', '--', 'docs/00.json'], ['-h', '', ''], ['--help', '', ''], ['--version', '', ''], ['-V', '', ''], ['--bogus', '.', 'docs/00.json'], ['-z', '.', 'docs/00.json'], ['', '', ''], ['-n', '', ''], ['--indent', '.', 'docs/00.json'], ['--indent abc', '.', 'docs/00.json'], ['--indent -1', '.', 'docs/00.json'], ['--indent 8', '.', 'docs/00.json'],
    ['-nr', '"x"', ''], ['-nc', '1', ''], ['-ncr', '[1]', ''], ['-cn', '1', ''], ['-sc', 'length', 'docs/multi.json'], ['-ce', 'false', 'docs/00.json'], ['--exit-status -c', '.n', 'docs/00.json'], ['--compact-output --raw-output', '.name', 'docs/00.json'], ['--null-input', '1', ''], ['--sort-keys -c', '.', 'docs/order.json'], ['--ascii-output -c', '.', 'docs/unicode.json'], ['--join-output', '.name', 'docs/00.json'], ['--tab -c', '.', 'docs/order.json'],
    ['--arg x', '.', 'docs/00.json'], ['--argjson x', '.', 'docs/00.json'], ['--rawfile x docs/lines.txt -c', '$x', 'docs/00.json'], ['--slurpfile x docs/multi.json -c', '$x', 'docs/00.json'], ['--rawfile x docs/missing -c', '$x', 'docs/00.json'], ['--slurpfile x docs/bad.json -c', '$x', 'docs/00.json'], ['-n -c --arg x 1 --arg x 2', '$x', ''], ['-n -c --arg x 1', '$ARGS.named', ''], ['-n -c --argjson x null', '$x', ''], ['-n -c --argjson x 1.0', '$x', ''], ['-n -c --argjson x 1e2', '$x', ''], ['-n -c --argjson x "\\"s\\"" ', '$x', ''], ['-n -c --argjson x "[1,2]"', '$x', ''], ['-n -c --argjson x "1 2"', '$x', ''], ['-n -c --argjson x ""', '$x', ''],
];

$tsv = fopen($outDir . '/cases.tsv', 'wb');
foreach ($cases as [$flags, $filter, $file]) {
    fwrite($tsv, $flags . "\x1f" . $filter . "\x1f" . $file . "\n");
}
foreach ($modes as [$flags, $filter, $file]) {
    fwrite($tsv, str_replace('docs/', $outDir . '/docs/', $flags) . "\x1f" . $filter . "\x1f" . str_replace('docs/', $outDir . '/docs/', $file) . "\n");
}
fclose($tsv);
file_put_contents($outDir . '/docs/prog.jq', "# comment\n.users | length\n");
echo \count($cases) + \count($modes), " cases\n";
