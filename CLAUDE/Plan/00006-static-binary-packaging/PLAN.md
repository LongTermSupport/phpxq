# Plan 00006: static binary packaging

**Status**: Not Started
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: Medium
**Recommended Executor**: Sonnet
**Execution Strategy**: Single-Threaded

## Overview

Ship phpxq as a self-contained executable that needs no PHP installed, plus a plain PHAR for
users who already have PHP 8.5. The route researched for this project is a Box-built PHAR combined
with a static-php-cli "micro" SFX runtime, built per platform in CI and attached to releases.

Parent epic: Plan 00001.

## Goals

- A PHAR build (Box) and a native binary (static-php-cli `micro:combine`) from the same sources.
- Linux (x86_64, arm64) and macOS builds at minimum; Windows if feasible.
- Release automation attaching the artefacts to GitHub releases.
- A one-line install path (install script, and optionally a Homebrew tap).
- Smallest viable PHP extension set in the static runtime; the extensions actually used are
  declared in `composer.json` `require` as `ext-*`.

## Non-Goals

- A web server or application server build (FrankenPHP is not needed).
- Changing phpxq functionality.

## Context & Background

- Route researched: Box (`box-project/box`) for the PHAR; static-php-cli
  ([crazywhalecc/static-php-cli](https://github.com/crazywhalecc/static-php-cli)) `spc` builds
  `micro.sfx`; `spc micro:combine` joins it with the PHAR into one executable. Re-verify the
  current commands and any breaking changes at implementation time.
- The static runtime changes startup behaviour (no extension loading, different OPcache and JIT
  situation); the benchmark suite (Plan 00005) must benchmark the packaged binary, and the
  results feed Plan 00007.

## Tasks

### Phase 1: Design

- [ ] ⬜ **Task 1.1**: Verify current static-php-cli and Box usage; record exact versions and commands in a supporting doc
- [ ] ⬜ **Task 1.2**: Decide the extension set and platform matrix; record decisions

### Phase 2: Build

- [ ] ⬜ **Task 2.1**: `bin/phpxq` entry point and `box.json`; PHAR builds and runs
- [ ] ⬜ **Task 2.2**: Local binary build script using static-php-cli; binary runs on a machine without PHP
- [ ] ⬜ **Task 2.3**: GitHub Actions matrix building and releasing per-platform artefacts
- [ ] ⬜ **Task 2.4**: Install script and (optional) Homebrew tap

### Phase 3: Verify

- [ ] ⬜ **Task 3.1**: Run the conformance harness (Plan 00002) against the packaged binary, not only the PHP entry point
- [ ] ⬜ **Task 3.2**: Smoke-test install on clean containers
- [ ] ⬜ **Task 3.3**: This project's QA gate passes (`vendor/bin/qa`)

## Dependencies

- Depends on: Plan 00001 (parent epic); a working tool from Plan 00003 and/or Plan 00004
- Blocks: Plan 00007 (optimise what is shipped)
- Related: Plan 00005

## Success Criteria

- [ ] A released binary runs on a clean machine with no PHP installed
- [ ] The conformance suites pass against the binary
- [ ] Install instructions are in the README
- [ ] This project's QA gate passes

## Risks & Mitigations

| Risk | Impact | Probability | Mitigation |
| ---- | ------ | ----------- | ---------- |
| Static build differs from the distro PHP (extensions, behaviour) | Medium | Medium | Run the full conformance suite on the binary |
| Cross-platform CI cost and flakiness | Low | Medium | Start with Linux, add platforms incrementally |

## Delivery & Milestones

- None yet.
