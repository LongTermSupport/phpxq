# Plan 00008: xdebug auto disable

**Status**: Complete
**Created**: 2026-10-05
**Owner**: dev
**Priority**: Medium

## Overview

Xdebug slows every PHP call and uses far more native stack, which skews benchmarks, makes the CLI slow for
anyone who leaves it loaded, and forces a lower YAML nesting limit. `bin/phpxq` should detect a loaded Xdebug
with an active mode and re-execute itself with `XDEBUG_MODE=off`, unless the user opts in with
`PHPXQ_ALLOW_XDEBUG`. This is what PHPStan does through composer/xdebug-handler; phpxq takes no production
dependencies, so it is a small class of its own.

`pcntl_exec` replaces the process, so there is no second PHP process and no cost on hosts without Xdebug (every
production host). Without `pcntl` (the static binary may lack it) the CLI carries on with Xdebug loaded.

## Goals

- A loaded, active Xdebug is switched off for the CLI by default; `PHPXQ_ALLOW_XDEBUG` keeps it.
- No measurable startup cost when Xdebug is not loaded.
- `Node::maxDepth()` (10000, or 5000 while an Xdebug mode is active) stays correct for the opt-in case.

## Non-Goals

- Changing how PHPUnit, Infection or php-qa-ci load Xdebug for coverage.

## Tasks

### Phase 1: Depth limit follows Xdebug

- [x] ✅ **Task 1.1**: `Node::maxDepth()` with `MAX_DEPTH` 10000 and `MAX_DEPTH_UNDER_XDEBUG` 5000, readers and tests updated

### Phase 2: Re-execute without Xdebug

- [x] ✅ **Task 2.1**: Failing unit tests for the `Cli\Xdebug` decision (loaded, mode, opt-in variable, no pcntl)
- [x] ✅ **Task 2.2**: `Cli\Xdebug` class, wired into `bin/phpxq` before any other work
- [x] ✅ **Task 2.3**: Stack-overflow and coverage tests set `PHPXQ_ALLOW_XDEBUG` where they need Xdebug on
- [x] ✅ **Task 2.4**: Document the variable in the README and the changelog

## Success Criteria

- [x] With Xdebug coverage loaded, `bin/phpxq` runs with it off; with `PHPXQ_ALLOW_XDEBUG=1` it stays on
- [x] Full unfiltered `CI=true vendor/bin/qa` exits 0 (covered MSI 91%)

## Delivery & Milestones

- Delivered in the commit that carries this plan's completion.
