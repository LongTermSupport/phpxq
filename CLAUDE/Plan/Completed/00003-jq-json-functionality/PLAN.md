# Plan 00003: jq json functionality

**Status**: Complete (delivered in b995067; conformance re-verified at 40a9aba)
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

## Technical Decisions

Full reasoning and the file ownership map for parallel workers: [architecture.md](architecture.md).

- **Target version**: jq 1.8.2 (pinned in `tests/Conformance/Jq/fixtures/NOTICE.md`), behaving as a build with
  decNumber (the `have_decnum` branches of `jq.test` are the expected ones).
- **Evaluator**: compile the AST to PHP closures in push-style continuation passing (`Filter::run($input, $emit)`), with a second `paths()` method per filter for path expressions; early exit via an internal
  `BreakException`. Chosen over Generators (per-output allocation) and a bytecode VM (more code, no PHP
  advantage).
- **Value model**: null, bool, `int|float|PreciseNumber`, string, PHP list for arrays, `Json\JsonObject` for
  objects (insertion ordered, keys always strings, numeric-string keys preserved by casting on read).
- **Number semantics**: arithmetic is IEEE double; results are PHP `int` when integral and `|n| <= 2^53`, else
  `float`. A literal that does not survive a double round trip (large integers, long decimals, `1E+1000`) is
  carried as `PreciseNumber(value, literal)` and printed back verbatim while it is unchanged; any arithmetic
  or builtin math collapses it to double, as jq 1.8 does. Double output uses the shortest round-trip digits in
  jq 1.8's `jvp_dtoa_fmt` layout (integral values without fraction, signed two-digit exponents, `nan` as
  `null`, infinities as the largest finite double). Exact switch points are pinned by the encoder tests,
  derived from the pinned suite, not from the jq 1.6 on developer machines.
- **Errors**: `JqException` carries a jq value; `JqCompileException` (exit 3); `BreakException` for
  `label`/`break` and native early stop; `HaltException` for `halt`.
- **Builtins**: natives registered by name/arity (`ValueBuiltinInterface` for pure functions with cartesian-product
  arguments, `StreamBuiltinInterface`/`PathStreamBuiltinInterface` for closure parameters and generators), plus a jq-source
  prelude for definitions that are short in jq; three providers (core, regex, date) so owners never share a file.
- **Modules**: `ModuleLoaderInterface` with jq's search rules; the compiler compiles each module in its own
  scope and exposes defs under the import alias.
- **CLI**: `Jq\Cli\JqApplication::run()` wired from `FrontController`; exit codes in `JqExitCode`.

## Tasks

### Phase 1: Design

- [x] ✅ **Task 1.1**: Write an architecture supporting doc: pipeline stages, AST, evaluator model for generators/backtracking, number representation, error model
- [x] ✅ **Task 1.2**: Record the number-semantics decision (big integers, float output) in the plan's Technical Decisions

### Phase 2: Core (TDD, each task driven by upstream `.test` cases)

- [x] ✅ **Task 2.1**: JSON parser and serialiser, including invalid-input error behaviour
- [x] ✅ **Task 2.2**: Lexer and parser for the jq language
- [x] ✅ **Task 2.3**: Evaluator: identity, field and index access, slices, iteration, pipes, comma, literals
- [x] ✅ **Task 2.4**: Operators, comparison, ordering and the alternative operator
- [x] ✅ **Task 2.5**: Variables, destructuring, function definitions, closures, recursion
- [x] ✅ **Task 2.6**: reduce, foreach, limit-style generators, label/break, try/catch, error handling
- [x] ✅ **Task 2.7**: Paths and assignment operators
- [x] ✅ **Task 2.8**: String interpolation, `@format` strings
- [x] ✅ **Task 2.9**: Builtins in groups, each against the suite
- [x] ✅ **Task 2.10**: Regex builtins, date/time builtins
- [x] ✅ **Task 2.11**: Modules (`import`, `include`) and `-L`

### Phase 3: CLI

- [x] ✅ **Task 3.1**: Argument handling and flags, `--arg`, `--args`, `--jsonargs`, `--slurp`, `--raw-input`, `--null-input`, `--seq`, `--stream` and the rest of the documented set
- [x] ✅ **Task 3.2**: Exit codes, stderr messages, `-e`
- [x] ✅ **Task 3.3**: Output options: indentation, sort keys, colour, ASCII output, raw output

### Phase 4: Conformance and QA

- [x] ✅ **Task 4.1**: Run the upstream suites via the Plan 00002 harness; triage every failure into fix or justified gap
- [x] ✅ **Task 4.2**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00002 (harness), Plan 00001 (parent epic)
- Blocks: Plan 00006, Plan 00007
- Related: Plan 00004, Plan 00005

## Success Criteria

- [x] Upstream jq suites pass, with only justified, listed gaps (878 of 879 pass; the 1 gap is justified in `tests/Conformance/Jq/known-gaps.txt`; jq shell suite passes)
- [x] No production dependency was added (`composer.json` `require` is php and ext-ctype, ext-json, ext-mbstring only)
- [x] This project's QA gate passes

## Risks & Mitigations

| Risk                                  | Impact | Probability | Mitigation                                                                           |
| ------------------------------------- | ------ | ----------- | ------------------------------------------------------------------------------------ |
| Backtracking evaluator is slow in PHP | High   | Medium      | Design with generators and compile-to-closures in mind; measure via Plan 00005 early |
| Oniguruma regex differs from PCRE     | Medium | High        | Document the translation layer and its limits; justified gaps for the remainder      |
| Number formatting mismatches          | Medium | High        | Decision in Phase 1; dedicated tests                                                 |

## Delivery & Milestones

- Implementation, conformance triage and QA conformance merged to main; final hardening round merged in b995067.
- `scripts/conformance.bash all`: jq 879 cases, 878 pass, 1 justified known gap, 0 unexpected; jq shell suite passes.
