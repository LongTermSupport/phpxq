# Plan 00001: epic initial build

**Status**: In Progress
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Sub-Agent Orchestration

## Overview

Epic tracking the initial build of phpxq: pure-PHP command-line equivalents of
[jq](https://github.com/jqlang/jq) (JSON) and [yq](https://github.com/mikefarah/yq) (YAML),
shipped as a standalone binary and then tuned for raw command-line speed.

This plan is the high-level tracker only. Each deliverable has its own plan below; detail lives
in those plans, not here. A single GitHub issue mirrors this epic for human progress tracking
(status only, never a journal).

The goal is **equivalence, not invention**: no new functionality, no new query language. Where jq
or yq defines behaviour, phpxq matches it. Performance matters more than elegant PHP: the
optimisation round will accept ugly code for measured speed.

## Goals

- jq-compatible JSON processing and yq-compatible YAML processing in pure PHP 8.5 with no
  production dependencies.
- Compatibility demonstrated by running the upstream test suites against phpxq.
- Distributed as a single installable binary.
- Measured, profiler-driven performance work after correctness, against a benchmark baseline.

## Non-Goals

- Features that jq or yq do not have.
- Production (runtime) Composer dependencies.
- Readable or idiomatic code at the expense of measured performance (applies to the
  optimisation round only; correctness work stays conventional).

## Context & Background

- Environment: PHP 8.5, Composer dev dependencies only (`lts/php-qa-ci`); see `README.md`.
- Upstream suites exist for both tools; the conformance plan records exactly where and how.
- Binary route researched: Box PHAR plus static-php-cli micro SFX; detail in plan 00006.
- Human tracking issue: https://github.com/LongTermSupport/phpxq/issues/1 (status checklist only).

## Tasks

### Phase 1: Foundations

- [x] ✅ **Task 1.1**: Plan 00002 - upstream conformance test harness (runs jq and yq upstream suites)
- [ ] ⬜ **Task 1.2**: Plan 00005 - benchmarking suite (baseline before and after optimisation)

### Phase 2: Functionality

- [ ] ⬜ **Task 2.1**: Plan 00003 - jq JSON functionality
- [ ] ⬜ **Task 2.2**: Plan 00004 - yq YAML functionality

### Phase 3: Shipping

- [ ] ⬜ **Task 3.1**: Plan 00006 - static binary packaging

### Phase 4: Performance

- [ ] ⬜ **Task 4.1**: Plan 00007 - performance optimisation round (profile, find hot spots, micro-optimise)

### Phase 5: Close-out

- [ ] ⬜ **Task 5.1**: Keep the GitHub tracking issue in step with the sub-plan statuses
- [ ] ⬜ **Task 5.2**: Verify every success criterion and close the epic

## Dependencies

- Depends on: Plan 00002 (conformance harness) for measuring Plans 00003 and 00004
- Depends on: Plans 00003 and 00004 for Plan 00006 and Plan 00007
- Related: Plan 00005 feeds Plan 00007

## Technical Decisions

### Decision 1: Which yq

**Context**: Two projects are called yq. mikefarah/yq is the Go tool with its own expression
language and native YAML handling. kislyuk/yq is a Python wrapper that feeds YAML through jq.
**Options Considered**:

1. mikefarah/yq - the common `yq`, own syntax, MIT, has an acceptance test directory
2. kislyuk/yq - jq syntax over YAML, so largely free once jq works
   **Decision**: Working assumption is mikefarah/yq; the owner has not confirmed it. Plan 00004
   holds the question open and must be confirmed before implementation starts.

## Success Criteria

- [ ] Plans 00002 to 00007 are Complete
- [ ] Upstream jq and yq conformance results are recorded, with known gaps listed
- [ ] A binary is published and installable
- [ ] Benchmarks show the post-optimisation improvement against the recorded baseline
- [ ] This project's QA gate passes

## Risks & Mitigations

| Risk                              | Impact | Probability | Mitigation                                                           |
| --------------------------------- | ------ | ----------- | -------------------------------------------------------------------- |
| Full jq language surface is large | High   | High        | Drive scope from the upstream suite, not from memory                 |
| PHP too slow versus C/Go tools    | Medium | Medium      | Benchmark early; optimisation round is a first-class plan            |
| YAML spec complexity in pure PHP  | High   | Medium      | Use the YAML test suite as the arbiter; scope recorded in Plan 00004 |

## Delivery & Milestones

- Plan workflow and sub-plans created.
