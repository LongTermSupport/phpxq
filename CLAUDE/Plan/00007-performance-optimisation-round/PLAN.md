# Plan 00007: performance optimisation round

**Status**: Not Started
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: Medium
**Recommended Executor**: Opus
**Execution Strategy**: Single-Threaded

## Overview

Once phpxq works end to end and can be shipped as a binary, run a profiler-driven optimisation
round: find the hot spots, micro-optimise them, and prove each gain against the benchmark
baseline. The goal is the fastest possible PHP command-line tool, not beautiful PHP. Readability
is explicitly traded for measured speed in the hot paths.

Parent epic: Plan 00001.

## Goals

- A recorded profile of the hot paths for each benchmark workload.
- Each optimisation is a separate, measured change with before and after numbers.
- Startup time minimised (autoloading, file count, eager versus lazy initialisation, preloading,
  OPcache and JIT settings where the shipped binary allows).
- No loss of conformance: the upstream suites stay green after every change.

## Non-Goals

- New features.
- Optimising without measurement; guesses are not accepted as justification.

## Context & Background

- Profiling tooling (for example Xdebug profiler, XHProf, or Valgrind-based tools) is selected in
  Task 1.1 and recorded, not assumed.
- Typical PHP micro-optimisations to consider: avoiding function-call overhead in hot loops,
  string and array handling choices, generator versus array trade-offs, compiling jq/yq
  expressions to closures, avoiding repeated allocation, reducing autoload cost, and a single
  concatenated source file for the shipped build. Each must be measured before adoption.
- Hot-path code gets a short comment naming the benchmark that justifies its shape, so the next
  reader does not "clean it up".

## Tasks

### Phase 1: Profile

- [ ] ⬜ **Task 1.1**: Choose and document the profiler; make profiling a one-command workflow
- [ ] ⬜ **Task 1.2**: Profile every benchmark workload from Plan 00005; record hot spots in a supporting doc

### Phase 2: Optimise (repeat per hot spot)

- [ ] ⬜ **Task 2.1**: Startup path optimisation
- [ ] ⬜ **Task 2.2**: JSON parse and serialise hot paths
- [ ] ⬜ **Task 2.3**: Evaluator hot paths
- [ ] ⬜ **Task 2.4**: YAML parse and emit hot paths
- [ ] ⬜ **Task 2.5**: Build-level optimisation of the shipped binary (extension set, preloading, concatenation)

### Phase 3: Verify

- [ ] ⬜ **Task 3.1**: Re-run conformance (Plan 00002) and benchmarks (Plan 00005); record before and after
- [ ] ⬜ **Task 3.2**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00001 (parent epic), Plan 00003, Plan 00004, Plan 00005, Plan 00006
- Related: Plan 00002

## Success Criteria

- [ ] Each optimisation has recorded before and after benchmark numbers
- [ ] Conformance results are unchanged
- [ ] Overall improvement against the Plan 00005 baseline is recorded
- [ ] This project's QA gate passes

## Risks & Mitigations

| Risk | Impact | Probability | Mitigation |
| ---- | ------ | ----------- | ---------- |
| Optimisation breaks conformance | High | Medium | Run the harness on every change |
| Unreadable hot paths get cleaned up later | Medium | Medium | Comment naming the justifying benchmark |

## Delivery & Milestones

- None yet.
