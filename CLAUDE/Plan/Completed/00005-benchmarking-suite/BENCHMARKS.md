# Benchmark harness: usage and methodology

Supporting document for Plan 00005. The harness measures phpxq against the reference `jq` and
`yq` binaries and against its own earlier runs.

## One command

```bash
scripts/bench/bench.bash run                    # measure, store JSON + Markdown under untracked/bench/
scripts/bench/bench.bash baseline --label v0.1  # same, stored as benchmarks/baselines/v0.1.json
scripts/bench/bench.bash run --compare benchmarks/baselines/v0.1.json   # print report with before/after ratios
scripts/bench/bench.bash report untracked/bench/<run>.json [--baseline FILE]
```

`run` prints the paths of the result JSON and the Markdown report. Options: `--label`, `--reps` (default
7), `--warmup` (default 2), `--sizes small,medium,large` (default `small,medium`; `large` is 100,000
records and slow with the reference tools), `--tools jq,yq`, `--filter TEXT`, `--binary PATH` (also
benchmark a packaged phpxq binary as `phpxq-bin-jq` / `phpxq-bin-yq`), `--out FILE`.

## Architecture

| Piece         | Where                        | Job                                                                        |
| ------------- | ---------------------------- | -------------------------------------------------------------------------- |
| Front script  | `scripts/bench/bench.bash`   | discovers targets, loops workloads, calls the measurer, then records       |
| Measurer      | `scripts/bench/measure.bash` | warm-up, timed repetitions of one command, one TSV line out                |
| PHP helper    | `scripts/bench/bench.php`    | `plan` (generate corpora, list workloads), `record`, `report`              |
| Harness code  | `tests/Support/Bench/`       | corpus generator, workload catalogue, stats, result store, report renderer |
| Harness tests | `tests/Unit/Support/Bench/`  | unit tests, part of the default `unit` suite                               |

Timing and process spawning live in bash on purpose: the project's security guard rejects PHP process
spawning in written code, and bash `EPOCHREALTIME` gives microsecond wall-clock timing without a
dependency. The PHP side owns everything with logic (statistics, formats, comparison) so it is unit
tested.

## Workloads

Defined in `tests/Support/Bench/WorkloadCatalogue.php`, per tool (`jq:` and `yq:` prefixes).

| Id suffix                       | Corpus                  | Purpose                                                      |
| ------------------------------- | ----------------------- | ------------------------------------------------------------ |
| `startup`                       | tiny (a few bytes)      | process start plus parse of trivial input; dominates CLI use |
| `many-small`                    | tiny                    | 20 invocations per sample: many-small-invocations pattern    |
| `identity-{small,medium,large}` | records                 | parse and re-serialise throughput                            |
| `select-{small,medium,large}`   | records                 | streaming filter `.[] \| select(.active) \| .name`           |
| `aggregate-{medium,large}`      | records                 | `map(.score) \| unique \| length`                            |
| `group-{medium,large}`          | records                 | `group_by` plus object construction                          |
| `wide-keys`                     | one object, 20,000 keys | wide document                                                |
| `deep-walk`                     | 100-level nesting       | deep document, recursive descent                             |

Corpora (`CorpusGenerator`) are deterministic: contents depend only on the corpus name (index-derived
values, no clock or random seed), so every machine generates byte-identical inputs. They are written to
`untracked/bench/corpus/` on demand and never committed. Record corpora: 100 (small), 5,000 (medium),
100,000 (large) records.

## Methodology

- One sample is the wall-clock time of one invocation (or, for `many-small`, 20 consecutive
  invocations) including process start, in milliseconds.
- The first warm-up run is also a status probe: exit 0 continues; stderr containing "not implemented"
  records `not-implemented`; any other failure records `failed` with the stderr head. No timing is
  attempted for non-ok targets, so stubs and unsupported filters cost nothing.
- `--warmup` runs are discarded; `--reps` timed samples are kept in full (`samplesMs`).
- Statistics per workload: min, median, mean, p95, max, standard deviation, and the coefficient of
  variation (CV %). Compare medians; treat a CV above roughly 10% as noisy and re-run on a quieter machine.
- The reference tool for a ratio is the real `jq` or `yq` of the same tool name. "vs ref" is
  phpxq median / reference median (above 1.00x means phpxq is slower).
- Reference `yq` must be mikefarah/yq (its `--version` must mention mikefarah); anything else, or
  absence, is recorded as `unavailable` and the run continues. The same holds for `jq`.
- Filters shared by both tools are kept to the common subset (yq has no `add` or `reduce`).

## Result format

`untracked/bench/<label>-<utc>.json` (schema 1) holds `label`, `startedAt`, `environment`, `settings`,
`targets` (id, tool, role `subject`/`reference`, version, command) and `measurements` (target, workload,
status `ok|failed|not-implemented|unavailable`, output bytes, note, raw samples, stats). A `.md` report with
the same stem is written beside it. The environment block records PHP version and binary, OPcache
CLI enablement, JIT mode and buffer size, Xdebug, memory limit, CPU model and core count, kernel, OS and
hostname. Results from different environments are not comparable; `--compare` prints a warning when the
CPU model or PHP version differs from the baseline's.

Note: PHP CLI often runs with `opcache.enable_cli=0` and `opcache.jit=disable`. The harness records
whatever the helper process uses and stores the subject command in the `subject_php` setting. To benchmark
phpxq under a tuned configuration set `BENCH_PHP`, for example
`BENCH_PHP="php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M" scripts/bench/bench.bash run`.

## Baseline workflow for Plan 00007

1. On the quiet benchmark machine, before optimising: `scripts/bench/bench.bash baseline --label before-opt --sizes small,medium,large`.
2. Commit `benchmarks/baselines/before-opt.json` together with the machine description it contains.
3. After each optimisation: `scripts/bench/bench.bash run --compare benchmarks/baselines/before-opt.json`
   and read the `vs base` column (below 1.00x is faster).

A first baseline against working jq/yq implementations (Task 3.1) can only be taken once Plans 00003
and 00004 deliver; until then a run records `not-implemented` for phpxq and real numbers for the
reference tools.
