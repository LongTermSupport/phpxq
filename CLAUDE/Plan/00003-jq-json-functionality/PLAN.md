# Plan 00003: jq json functionality

**Status**: Not Started
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Sub-Agent Orchestration

## Overview

Implement a pure-PHP equivalent of jq: command-line interface, JSON reader and writer, and the jq
language (lexer, parser, evaluator, builtins). The aim is behavioural equivalence with the
reference jq, as measured by the upstream suite (Plan 00002). No new features, no deviations.

Parent epic: Plan 00001.

## Goals

- CLI flags and exit codes matching jq.
- Full jq filter language: paths, pipes, generators and backtracking, reduce/foreach, functions,
  variables, destructuring, try/catch, label/break, string interpolation and formats, assignment
  operators, comments.
- Builtins matching jq, including regex, date/time, math and SQL-style builtins, as the upstream
  suite requires.
- Output formatting matching jq (indent, `-c`, `-r`, `-S`, colour, number formatting).
- Upstream `jq.test`, `man.test` and related suites passing, with justified known gaps only.

## Non-Goals

- Anything jq does not do.
- Performance tuning (Plan 00007), beyond avoiding architecture that blocks it.
- YAML (Plan 00004).

## Context & Background

- Reference implementation: [jqlang/jq](https://github.com/jqlang/jq). The reference version is
  pinned in Plan 00002 and must be the one this plan targets.
- Number semantics are a known sore point: jq's handling of large integers and float formatting
  must be matched deliberately and recorded as a decision.
- Design should keep the evaluator and parser separable so Plan 00004 can reuse them if the yq
  target turns out to share jq syntax.

## Tasks

### Phase 1: Design

- [ ] ⬜ **Task 1.1**: Write an architecture supporting doc: pipeline stages, AST, evaluator model for generators/backtracking, number representation, error model
- [ ] ⬜ **Task 1.2**: Record the number-semantics decision (big integers, float output) in the plan's Technical Decisions

### Phase 2: Core (TDD, each task driven by upstream `.test` cases)

- [ ] ⬜ **Task 2.1**: JSON parser and serialiser, including invalid-input error behaviour
- [ ] ⬜ **Task 2.2**: Lexer and parser for the jq language
- [ ] ⬜ **Task 2.3**: Evaluator: identity, field and index access, slices, iteration, pipes, comma, literals
- [ ] ⬜ **Task 2.4**: Operators, comparison, ordering and the alternative operator
- [ ] ⬜ **Task 2.5**: Variables, destructuring, function definitions, closures, recursion
- [ ] ⬜ **Task 2.6**: reduce, foreach, limit-style generators, label/break, try/catch, error handling
- [ ] ⬜ **Task 2.7**: Paths and assignment operators
- [ ] ⬜ **Task 2.8**: String interpolation, `@format` strings
- [ ] ⬜ **Task 2.9**: Builtins in groups, each against the suite
- [ ] ⬜ **Task 2.10**: Regex builtins, date/time builtins
- [ ] ⬜ **Task 2.11**: Modules (`import`, `include`) and `-L`

### Phase 3: CLI

- [ ] ⬜ **Task 3.1**: Argument handling and flags, `--arg`, `--args`, `--jsonargs`, `--slurp`, `--raw-input`, `--null-input`, `--seq`, `--stream` and the rest of the documented set
- [ ] ⬜ **Task 3.2**: Exit codes, stderr messages, `-e`
- [ ] ⬜ **Task 3.3**: Output options: indentation, sort keys, colour, ASCII output, raw output

### Phase 4: Conformance and QA

- [ ] ⬜ **Task 4.1**: Run the upstream suites via the Plan 00002 harness; triage every failure into fix or justified gap
- [ ] ⬜ **Task 4.2**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00002 (harness), Plan 00001 (parent epic)
- Blocks: Plan 00006, Plan 00007
- Related: Plan 00004, Plan 00005

## Success Criteria

- [ ] Upstream jq suites pass, with only justified, listed gaps
- [ ] No production dependency was added
- [ ] This project's QA gate passes

## Risks & Mitigations

| Risk | Impact | Probability | Mitigation |
| ---- | ------ | ----------- | ---------- |
| Backtracking evaluator is slow in PHP | High | Medium | Design with generators and compile-to-closures in mind; measure via Plan 00005 early |
| Oniguruma regex differs from PCRE | Medium | High | Document the translation layer and its limits; justified gaps for the remainder |
| Number formatting mismatches | Medium | High | Decision in Phase 1; dedicated tests |

## Delivery & Milestones

- None yet.
