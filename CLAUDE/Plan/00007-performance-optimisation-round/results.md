# Plan 00007: jq performance results

Subject: `php bin/phpxq jq` (PHP 8.5.11 CLI, opcache off for CLI, no JIT). Reference: jq 1.6 on PATH.
Baseline: `benchmarks/baselines/perf-jq-before.{json,md}` (commit 69a7860, recorded with
`scripts/bench/bench.bash baseline`, wall clock, quiet host).

## Method

- Profiling: no Xdebug in the image. `scripts/bench/profile.bash <size> <filter>` is a pcntl signal
  sampling profiler (`tests/Support/Bench/SamplingProfiler.php`) reporting SELF and INCLUSIVE shares.
- The host was heavily loaded by concurrent lanes while the after numbers were taken (load average
  above 40, the real jq itself ran about 2x slower than in the baseline), so wall-clock medians are
  not comparable. The table below is user+sys CPU milliseconds, the minimum of 3 runs, taking the
  before (main, 69a7860 tree) and after (this branch) with jq 1.6 measured in the same loop.
  Script: `scripts/bench/cpu-compare.bash <before checkout> [reps]`, same workloads and corpora as
  `WorkloadCatalogue`.

## Before and after (CPU ms, lower is better)

| workload         | before | after | after / before | jq 1.6 |
| ---------------- | -----: | ----: | -------------: | -----: |
| startup          |    112 |    71 |           0.63 |     40 |
| identity-small   |    123 |    75 |           0.61 |     43 |
| select-small     |    132 |    62 |           0.47 |     18 |
| identity-medium  |    186 |   133 |           0.72 |    134 |
| select-medium    |    166 |   101 |           0.61 |     91 |
| aggregate-medium |    165 |   102 |           0.62 |    107 |
| group-medium     |    261 |   136 |           0.52 |    119 |
| identity-large   |   2076 |  1436 |           0.69 |   1926 |
| select-large     |   1111 |   863 |           0.78 |   1016 |
| aggregate-large  |   1141 |   741 |           0.65 |   1926 |
| group-large      |   4185 |  1455 |           0.35 |   1851 |
| wide-keys        |    246 |   305 |        (noise) |     68 |
| deep-walk        |    117 |    65 |           0.56 |     41 |

wide-keys: the 3-run minimum was disturbed by load; five paired single runs gave before 162-190 ms,
after 94-105 ms.

## Optimisations (each in its own commit)

| change                                                                                                                                                                                                                          | commit  | measured effect                                                                                                                     |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| native `walk`/`..` op, `array_multisort` based `sort_by`/`group_by` (fallback to the generic comparison for mixed keys), same-type shortcut in `Values::compare`, encoder key cache, decoder convert without placeholder checks | 26d90cf | group-large 4185 to about 1450 ms, group-medium 261 to 136, deep-walk 117 to 65, identity-medium 12% from the plain decoder convert |
| lazy builtin groups (`BuiltinCatalog`), lazy prelude definitions (one definition per line, parsed on first use), lazy stream parser, closure PSR-4 autoloader in `bin/phpxq`                                                    | f9562b0 | startup 112 to 71 ms CPU (bare `php` with the default ini is about 56 ms of that)                                                   |
| cycle collector off during a run, manual collection every 4096 inputs                                                                                                                                                           | 58bd249 | many-small and large inputs: fewer GC passes over acyclic data                                                                      |

Tried and rejected: `opcache.file_cache` with `opcache.enable_cli=1` for the startup path (67 versus
68 ms CPU, no gain worth a runtime requirement).

## Equivalence safeguards

- `testSortByAndGroupByAgreeWithTheGenericComparisonOnAnyKeyShape` compares the native sort and group
  against `Values::compare` over 300 random key shapes including NaN, big integers and mixed types.
- Conformance (`scripts/conformance.bash all`) and the unit suite stay green after every change.
