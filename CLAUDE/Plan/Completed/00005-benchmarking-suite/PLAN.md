# Plan 00005: benchmarking suite

**Status**: Complete (delivered in 3d22c46; first baselines stored in 84b6cb2 and 581aae3)
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High
**Recommended Executor**: Sonnet
**Execution Strategy**: Single-Threaded

## Overview

Build a repeatable benchmark suite that shows where phpxq stands, first against the reference jq
and yq binaries and then against its own history. It provides the baseline for the performance
optimisation round (Plan 00007) and a regression guard afterwards. Benchmarks start as soon as
there is anything to measure and grow with the functionality.

Parent epic: Plan 00001.

## Goals

- Representative workloads: startup time on tiny input (dominant for CLI use), large-file
  throughput, deep and wide documents, heavy filters, and many-small-invocations.
- Comparison runs against reference jq and yq, and against earlier phpxq results.
- Results stored in a machine-readable format and summarised for humans.
- Stable methodology: warm-up, repetitions, variance reporting, recorded hardware and PHP
  configuration (OPcache and JIT settings matter and are recorded).

## Non-Goals

- Optimising anything (Plan 00007).
- Benchmarks as pass/fail CI gates before there is a stable baseline.

## Context & Background

- Startup cost is expected to matter most for a CLI tool; the binary route (Plan 00006) changes
  startup characteristics, so the suite must be able to benchmark both `php bin/phpxq` and the
  packaged binary.
- Benchmarks should run on a quiet, dedicated machine (the work moves to a data centre server,
  which suits this); results from different machines are not comparable and are labelled.

## Technical Decisions

Usage, workloads, methodology and result format are in [BENCHMARKS.md](BENCHMARKS.md).

- **Measurement tool**: no external dependency (hyperfine is not installed and adding it would be
  an environment change). Bash `measure.bash` times wall-clock with `EPOCHREALTIME`; warm-up and
  repetitions are explicit; the first warm-up run is a status probe so stubs and unsupported filters
  are recorded (`not-implemented`, `failed`, `unavailable`) instead of aborting the run.
- **Language split**: bash spawns and times processes (the repository's security hook forbids PHP
  process spawning in written code); PHP holds all logic that has behaviour (corpus generation, workload
  catalogue, statistics, result store, report), unit tested in `tests/Unit/Support/Bench`.
- **Code location**: `tests/Support/Bench` (dev tooling, not shipped in the production package, same
  convention as the conformance helpers), namespace `LTS\PhpXq\Tests\Support\Bench`.
- **Corpora**: generated on demand, deterministic (index-derived values, no seed or clock), written to
  `untracked/bench/corpus/`; nothing large is committed. Deep corpus is 100 levels because jq 1.6 rejects
  deeper input.
- **Results**: JSON schema 1 plus a Markdown report; baselines live in `benchmarks/baselines/` and are
  compared with `--compare`. Environment (PHP, OPcache, JIT, CPU, kernel) is stored with each run and a
  mismatch with the baseline's CPU or PHP version prints a warning.
- **Shared filters** use only the subset both jq and mikefarah yq accept (yq lacks `add` and `reduce`).
- **Task 3.1** (first real baseline) is deferred until Plans 00003 and 00004 deliver; the workflow is in place.

## Tasks

### Phase 1: Design

- [x] ✅ **Task 1.1**: Choose workloads and corpus generation (deterministic generators, no large files committed)
- [x] ✅ **Task 1.2**: Choose the measurement tool and methodology; record the decision

### Phase 2: Build

- [x] ✅ **Task 2.1**: Corpus generator script
- [x] ✅ **Task 2.2**: Runner that executes phpxq and the reference tools, with warm-up and repetitions
- [x] ✅ **Task 2.3**: Result storage format and comparison report
- [x] ✅ **Task 2.4**: Record the environment (PHP version, OPcache and JIT settings, CPU, kernel) with each run

### Phase 3: Baseline

- [x] ✅ **Task 3.1**: First baseline run once Plans 00003 and 00004 have usable functionality; store it
- [x] ✅ **Task 3.2**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00001 (parent epic); meaningful baselines need Plan 00003 and Plan 00004
- Blocks: Plan 00007
- Related: Plan 00006

## Success Criteria

- [x] One command produces a comparable benchmark report (`scripts/bench/bench.bash run|baseline|report`)
- [x] A baseline is stored and reproducible (`benchmarks/baselines/perf-jq-before.*`, `yq-before.*`, `yq-after.*`)
- [x] Methodology and environment are recorded ([BENCHMARKS.md](BENCHMARKS.md); environment stored with each result)
- [x] This project's QA gate passes

## Delivery & Milestones

- Harness (corpora, runner, result store, report, environment capture) merged in 3d22c46.
- Task 3.1 baselines for jq and yq were recorded by the Plan 00007 rounds (84b6cb2, 581aae3) once Plans 00003 and 00004 were usable.
- Benchmarking the packaged binary is supported (`--binary PATH`); no binary results are stored yet (see Plan 00006).
