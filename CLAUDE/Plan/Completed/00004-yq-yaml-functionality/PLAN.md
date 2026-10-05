# Plan 00004: yq yaml functionality

**Status**: Complete (delivered in f8dd0e7; conformance re-verified at 40a9aba)
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: High
**Recommended Executor**: Opus
**Execution Strategy**: Sub-Agent Orchestration

## Overview

Implement a pure-PHP equivalent of yq: YAML reading and writing plus yq's expression language and
command line. As with jq, the aim is equivalence with the reference tool as measured by its
upstream tests (Plan 00002), with nothing new invented.

Parent epic: Plan 00001.

## Goals

- A YAML parser and emitter in pure PHP, covering the YAML features the reference yq supports,
  including anchors, aliases, multi-document streams, comments and style preservation as the
  reference does.
- The reference tool's expression language and CLI commands.
- Format conversion the reference supports (JSON, properties, CSV/TSV, XML) where the reference
  does so.
- Upstream acceptance tests passing, with justified known gaps only.

## Non-Goals

- Anything the reference yq does not do.
- A general-purpose YAML library API (internal use only).

## Context & Background

- **Open question, must be confirmed by the owner before implementation starts**: which yq is the
  reference. Working assumption is [mikefarah/yq](https://github.com/mikefarah/yq) (Go, MIT, own
  expression language). The alternative, [kislyuk/yq](https://github.com/kislyuk/yq), is a Python
  wrapper that runs YAML through jq, which would reduce this plan to a YAML-to-JSON bridge over
  Plan 00003. See Plan 00001 Decision 1.
- Round-trip fidelity (comments, key order, styles, anchors) is the hardest part of the
  mikefarah reference and shapes the data model.

## Tasks

### Phase 1: Confirm and design

- [x] ✅ **Task 1.1**: Owner confirms the reference yq; record the answer in Technical Decisions
- [x] ✅ **Task 1.2**: Supporting doc: scope of the YAML subset, node model that preserves comments, order and style, and how the expression layer relates to Plan 00003

### Phase 2: YAML core (TDD)

- [x] ✅ **Task 2.1**: YAML tokenizer and parser (block and flow collections, scalars, multi-line strings, documents)
- [x] ✅ **Task 2.2**: Anchors, aliases, merge keys, tags
- [x] ✅ **Task 2.3**: Comment and style capture
- [x] ✅ **Task 2.4**: YAML emitter with reference-compatible output

### Phase 3: Expression language and CLI

- [x] ✅ **Task 3.1**: Expression evaluator per the confirmed reference
- [x] ✅ **Task 3.2**: CLI commands and flags, in-place editing, multi-file handling, exit codes
- [x] ✅ **Task 3.3**: Format conversion in and out

### Phase 4: Conformance and QA

- [x] ✅ **Task 4.1**: Run the upstream suites via the Plan 00002 harness; triage every failure into fix or justified gap
- [x] ✅ **Task 4.2**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00002 (harness), Plan 00001 (parent epic); likely Plan 00003 for shared evaluator pieces, depending on Task 1.1
- Blocks: Plan 00006, Plan 00007
- Related: Plan 00005

## Technical Decisions

### Decision 1: Reference yq is mikefarah/yq

**Context**: Task 1.1 asked the owner to confirm which yq is the reference. The owner delegated the
choice to the orchestrator.
**Decision**: The reference is [mikefarah/yq](https://github.com/mikefarah/yq) at the tag pinned in
`tests/Conformance/Yq/fixtures/NOTICE.md`. The vendored conformance fixtures are that project's own
documentation examples and acceptance tests, so the harness already measures equivalence with it.
kislyuk/yq is out of scope. The expression language is therefore yq's own, not jq's, and Plan 00003's
evaluator is not reused.
**Design**: [architecture.md](architecture.md) records scope, node model, pipeline, interfaces and the
worker file ownership map.

## Success Criteria

- [x] Reference yq confirmed and recorded (owner delegated the choice; Decision 1)
- [x] Upstream yq tests pass, with only justified, listed gaps (565 of 574 pass; 9 justified gaps in `tests/Conformance/Yq/known-gaps.txt`; all 17 yq shell suites pass)
- [x] No production dependency was added (`composer.json` `require` is php and ext-ctype, ext-json, ext-mbstring only)
- [x] This project's QA gate passes

## Risks & Mitigations

| Risk                          | Impact | Probability | Mitigation                                                                    |
| ----------------------------- | ------ | ----------- | ----------------------------------------------------------------------------- |
| YAML spec is large and subtle | High   | High        | Use the official YAML test suite as an extra arbiter; record supported subset |
| Comment and style round-trip  | High   | High        | Node model designed for it up front (Task 1.2)                                |
| Pure-PHP parser is slow       | Medium | Medium      | Benchmark via Plan 00005; optimise in Plan 00007                              |

## Delivery & Milestones

- Parser, emitter, evaluator, CLI and format codecs merged to main; final hardening round merged in f8dd0e7.
- `scripts/conformance.bash all`: yq 574 cases, 565 pass, 9 justified known gaps, 0 unexpected; 17 yq shell suites pass.
