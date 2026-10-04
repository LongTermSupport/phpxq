# Plan 00007: yq performance results

Supporting document for [PLAN.md](PLAN.md). Every optimisation of the yq path is listed with its measured
before and after numbers. The jq lane records its own numbers separately.

## Method

- Machine: 8 cores (Xeon E-2236), PHP 8.5.11 CLI, OPcache disabled for the CLI, no JIT, no Xdebug. The host
  was shared with four other lanes (load average above 30), so wall-clock numbers are noise. Every number
  below is **CPU time** (user plus system, from the shell `time` keyword or `getrusage`), **minimum of N
  runs**, which is the only statistic that stayed stable under that load. A bare `php -r '1;'` costs 45 to
  50 ms of CPU on this host, which is the floor for every workload.
- Builds measured: production-like copies (`composer install --no-dev --classmap-authoritative`, as the
  PHAR build does) of the commit under test. A plain `php bin/phpxq` run loads the development vendor
  autoload files (the `thecodingmachine/safe` function files and PHPUnit helpers, about 40 ms of extra
  compile time per process), which a shipped binary never does; the harness numbers of the baseline file
  carry that extra cost.
- Before: commit `69a7860` (the state of main when the round started). Mid: `bca5c8d` (the parser and
  startup work already on this branch). After: the working tree at the commit named in each section.
- Profiling: a sampling profiler (`setitimer(ITIMER_PROF)` through FFI plus `pcntl` signals, one sample per
  millisecond of CPU) and `getrusage` phase timers; both live in `untracked/scratch` and are not shipped.
  Xdebug and XHProf are not installed here.
- Recorded baseline: `benchmarks/baselines/yq-before.json` (harness run, label `yq-before`, taken on
  `69a7860`; very noisy because of the shared host).

## Overall (CPU ms, min of 3, production-like build)

See "Final numbers" at the end of this file.

## Optimisations

Numbers are in-process (`phases.php`, medium corpus, 5000 records, 640 KB) unless stated.

### 1. Node: promote only the hot constructor parameters; clone prototypes in the fast parser

`Node` had sixteen promoted constructor parameters, so every `new Node()` paid sixteen assignments. Only
kind, tag, style, value, content, line and column are promoted now; the nine rarely used properties are
plain declarations with defaults (the engine copies defaults in one block). The single-pass parser builds
scalars and mappings by cloning a prototype and writing four properties, which a micro benchmark showed
costs 300 ns against 1200 ns for the constructor.

| step                             | before       | after                         |
| -------------------------------- | ------------ | ----------------------------- |
| `FastBlockParser::parse`, medium | 203 ms       | 139 ms                        |
| 200k node constructions (micro)  | 1200 ns each | 880 ns (ctor), 300 ns (clone) |

### 2. Emitter: runs of plain lines, and plain keys that open a block

`YamlWriter` already printed `key: value` lines through a fast path; two further cases were still
planning every scalar: a plain key whose value is a block collection (two of the eleven lines of a
record), and the `writeIndent()` call per line. A plain key opening a block now prints `key:` directly,
and after one plain line the next one is written as newline, indent, text (the writer state after a plain
line is known).

| step                        | before | after  |
| --------------------------- | ------ | ------ |
| `YamlEmitter::emit`, medium | 195 ms | 116 ms |

### 3. Cycle collector off for the duration of a yq run

Profiling a cold run showed the CLI spending about 130 ms more than the same work in a warm loop. The
cycle collector re-scans the node tree as objects are allocated and, with 100k+ nodes, runs often; the
tree has no garbage cycles worth collecting in a short-lived process. `YqApplication::run()` disables the
collector and restores its previous state in a `finally`.

| workload         | before | after  |
| ---------------- | ------ | ------ |
| identity-medium  | 471 ms | 359 ms |
| select-medium    | 508 ms | 268 ms |
| aggregate-medium | 388 ms | 310 ms |
| group-medium     | 677 ms | 483 ms |

### 4. Sorting: native sort for homogeneous keys, string compare without date probing

`sort_by` and `group_by` sorted with `usort` and a closure that ran `Compare::order()`, which for strings
tried to parse both sides as timestamps on every comparison. `Compare::order()` now returns early for
equal strings and for strings that cannot be dates, and `SortingCalls` sorts with the stable native
`asort` when every key is a plain non-date string or every key is a non-NaN number (anything else, and any
date layout, keeps the general comparison). Unit tests pin the order against the general path.

| step                            | before | after |
| ------------------------------- | ------ | ----- |
| `group_by(.group)` eval, medium | 208 ms | 97 ms |
| `sort_by(.score)` eval, medium  | 245 ms | 99 ms |

### 5. Fast parser: memoised implicit tags

Keys and many values repeat constantly in data files; the single-pass parser remembers the resolved tag
of each distinct plain text (bounded at 2048 entries).

| step                        | before | after  |
| --------------------------- | ------ | ------ |
| `YamlParser::parse`, medium | 119 ms | 101 ms |

### 6. Fast parser: quoted scalars and comments

Real configuration files carry comments and quoted strings, and one such line used to send the whole
document down the full scanner and parser. `FastBlockParser` now takes single-quoted scalars without a
doubled quote and double-quoted scalars without escapes (printable ASCII, one line), a line comment after
a value, and head comments that sit at the indent of the line they precede and touch it. Anything else
(a comment above `- key: value`, after a key with no value, a blank line between comment and node, a tab,
a trailing blank in a comment) still declines to the full parser. `FastBlockParserTest` pins every
accepted shape against the full parser (positions, comments and styles included) and fuzzes 400 generated
documents that now include comment lines and quoted words.

| step                                                                   | before  | after  |
| ---------------------------------------------------------------------- | ------- | ------ |
| `YamlParser::parse`, medium with a quoted name and comments per record | 1100 ms | 126 ms |

(`untracked/scratch/phases.php`, 5000 records, 721 KB, min of 5; the CLI output is byte-identical to the
previous builds.)

## Merge notes

`group_by` on main groups by first appearance (it no longer sorts), so the native-sort fast path now only
serves `sort_by`; the emitter fast path in `YamlWriter::emitBlockMapping()` stands down while a scalar
value's head comment is being carried to the next key.

## Measured and rejected

- Memoising the plain-word test in the emitter (a bounded per-writer cache): no measurable change in
  `emit` (107.9 ms against 107.4 ms), removed.
- Testing the key text before calling `NodeOps::isMergeKey()` in `Traversal`: eval of
  `.[] | select(.active) | .name` stayed at about 42 ms either way, removed.
- Startup: an in-process run of a tiny document spends about 11 ms in phpxq code (autoload 2 ms, building
  the application 3.5 ms, running 6 ms, 33 files compiled); the rest of the 60 ms is the PHP binary
  itself. Nothing left worth an ugly change without OPcache.
- Format codecs on 0.6 to 1 MB inputs (decode to YAML, 0.4 to 0.9 s CPU, encode 0.33 to 0.55 s): the
  profiles are flat (no function above 12 percent), so no codec-specific change was made.

## Final numbers

CPU ms (user plus system), minimum of 3 runs, production-like builds of `69a7860` (before) and this
branch (after), taken with `untracked/scratch/ba.bash` while the host load average was 12 to 30:

| workload            | before | after |
| ------------------- | -----: | ----: |
| startup             |     66 |    56 |
| identity-small      |    110 |    70 |
| identity-medium     |   2113 |   333 |
| select-medium       |   1699 |   273 |
| aggregate-medium    |   1632 |   284 |
| group-medium        |   2072 |   342 |
| wide-keys           |    644 |   149 |
| deep-walk           |     77 |    61 |
| json-in-medium      |    477 |   396 |
| yaml-to-json-medium |   1824 |   317 |
| identity-large      |  42367 |  6417 |
| select-large        |  39171 |  4852 |

The before numbers are dominated by the cycle collector re-scanning the tree (optimisation 3) and the
object-per-node parser cost.

Harness record: `benchmarks/baselines/yq-after.json` (label `yq-after`, `bench.bash baseline --tools yq --reps 3 --sizes small,medium`, wall clock on the loaded host, compared with `yq-before`). Median wall
ms against `yq-before`, with the ratio to mikefarah yq v4.54.1 on the same host in brackets: startup 90
(4.4x), identity-small 94 (2.4x), identity-medium 1075 (0.95x), select-medium 406 (0.96x),
aggregate-medium 443 (0.68x), group-medium 502 (0.64x), wide-keys 276 (1.45x), deep-walk 82 (2.3x),
many-small 2198 (5.5x). The remaining gap is process startup (the PHP binary itself costs 45 to 50 ms of
CPU) and the many-small workload (one process per file).

Conformance after the round: `scripts/conformance.bash all` prints CONFORMANCE: OK.
