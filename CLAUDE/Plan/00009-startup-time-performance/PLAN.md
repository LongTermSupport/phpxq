# Plan 00009: startup time performance

**Status**: Not Started
**Created**: 2026-10-05
**Owner**: dev
**Priority**: Medium

## Overview

Throughput is competitive with jq 1.6 and mikefarah yq on medium and large inputs, but startup is the weak spot:
about 71 ms from a checkout (jq) against about 40 ms for native jq 1.6, and 2 to 5x slower than yq for
many-small-files workloads. Bare PHP launch is 45 to 56 ms of that, so the controllable part is roughly 25 ms.
Plan 00007 already removed Composer's autoloader from the hot path; this plan looks for what is left.

Start from a measured profile, not guesses, and keep the same method as plan 00007 (CPU milliseconds, minimum of
N runs, differences under about 5 percent are noise).

## Goals

- Account for where the non-PHP startup milliseconds go for `jq` and `yq`.
- Cut the controllable startup cost measurably, and record what each change bought and what was rejected.

## Non-Goals

- Beating the native binaries' process launch cost.

## Tasks

### Phase 1: Measure

- [ ] ⬜ **Task 1.1**: Re-run `scripts/bench/bench.bash` on the current main as the baseline (after plan 00008 lands, so Xdebug is out of the picture)
- [ ] ⬜ **Task 1.2**: Profile class loading and file reads on a trivial filter

### Phase 2: Candidates

- [ ] ⬜ **Task 2.1**: OPcache file cache for the CLI and PHAR
- [ ] ⬜ **Task 2.2**: Load builtins and the yq format codecs lazily
- [ ] ⬜ **Task 2.3**: Create the large-stack Fiber only when the program needs deep recursion
- [ ] ⬜ **Task 2.4**: Record results next to plan 00007's

## Success Criteria

- [ ] A recorded before and after for startup on the checkout, the PHAR and the static binary
- [ ] Full unfiltered `CI=true vendor/bin/qa` exits 0

## Delivery & Milestones

- None yet.
