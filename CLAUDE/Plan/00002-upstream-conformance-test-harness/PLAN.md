# Plan 00002: upstream conformance test harness

**Status**: In Progress
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High
**Recommended Executor**: Sonnet
**Execution Strategy**: Single-Threaded

## Overview

Run the upstream jq and yq test suites against phpxq so compatibility is measured, not asserted.
The harness is dev-only tooling: it must never become a production dependency. It reports a
pass/fail/skip count per upstream suite and keeps an explicit, justified list of known gaps.

Parent epic: Plan 00001. This plan unblocks Plans 00003 and 00004, which use it as their
definition of "working".

## Goals

- A repeatable command that runs each upstream suite against the phpxq entry point.
- Upstream suites obtained without adding production dependencies (fetched as dev fixtures or via
  Composer `require-dev` source packages; the choice is recorded here once made).
- A conformance report: passed, failed, skipped, with every skip or known failure justified.
- Pinned upstream versions so results are reproducible.

## Non-Goals

- Fixing failures (that is Plans 00003 and 00004).
- Writing new tests of our own behaviour beyond what the harness needs (PHPUnit unit tests belong
  to the feature plans).

## Context & Background

Verified on 2026-10-03 against the upstream repositories:

- jq (`jqlang/jq`, default branch `master`) has `tests/` containing `jq.test`, `man.test`,
  `onig.test`, `optional.test`, `uri.test`, `base64.test`, shell tests `shtest` and
  `jq-f-test.sh`, and `modules/`. The `.test` files are a program/input/expected-output format.
- mikefarah/yq (default branch `master`, MIT) has `acceptance_tests/*.sh` (CLI behaviour) and Go
  tests under `pkg/`. Whether `pkg/yqlib` scenarios are extractable as data is an open question
  for Task 1.2.
- jq's licence is reported as NOASSERTION by GitHub; check licence terms before vendoring files.

## Tasks

### Phase 1: Investigate

- [ ] ⬜ **Task 1.1**: Read the jq `.test` file format and the shell test scripts; document the parse rules in a supporting doc
- [ ] ⬜ **Task 1.2**: Determine which yq tests are extractable (acceptance scripts, `pkg/yqlib` scenario data); document in a supporting doc
- [x] ✅ **Task 1.3**: Check upstream licences and decide fetch-on-demand versus vendor; record the decision (vendored with attribution; see each `fixtures/NOTICE.md`)

### Phase 2: Build

- [ ] ⬜ **Task 2.1**: Pin upstream versions and add a fetch script (dev only, no production dependency)
- [ ] ⬜ **Task 2.2**: Implement a runner for jq `.test` files against the phpxq entry point (TDD for the parser)
- [ ] ⬜ **Task 2.3**: Implement a runner for the yq acceptance scripts
- [ ] ⬜ **Task 2.4**: Known-gap list file with a justification per entry; the runner fails on unexpected failures and on unexpectedly passing gaps
- [ ] ⬜ **Task 2.5**: Summary report output (counts per suite) usable from CI

### Phase 3: QA

- [ ] ⬜ **Task 3.1**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00001 (parent epic)
- Blocks: Plan 00003, Plan 00004
- Related: Plan 00005

## Success Criteria

- [ ] One command runs both upstream suites and prints a per-suite summary
- [ ] Upstream versions are pinned and recorded
- [ ] Known gaps are justified and enforced
- [ ] No production dependency was added
- [ ] This project's QA gate passes

## Delivery & Milestones

- Red conformance suites in place: `jq` (all `.test` cases from jq 1.8.2) and `yq` (documented examples from yq v4.54.1) as named PHPUnit suites, excluded from the default run. Not yet done: the jq `shtest` shell script, the yq acceptance `.sh` scripts, the known-gap list and the summary report (Tasks 2.3 to 2.5).
