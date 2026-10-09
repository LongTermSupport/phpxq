# Plan 00011: raise mutation and coverage floors

**Status**: Not Started
**Created**: 2026-10-08
**Owner**: dev
**Priority**: High

## Overview

The code review (plan 00010, finding Q1) showed that the 90% mutation floor measured under a third of the code, because the slow conformance suite made Infection skip about 70% of mutants. Measuring the unit suite alone is honest but scores far lower, so the floors are set at the measured values and ratchet upwards. This plan climbs them to the 90 target by adding mutation-killing unit tests.

## Goals

- Mutation score indicator and covered MSI at 90 or higher on the unit suite
- Line coverage floor at or above 95 and method coverage floor at or above 90
- Skipped mutants stay a tiny fraction of the total

## Non-Goals

- Lowering any floor, or excluding code from measurement to reach a number
- Counting conformance runs as mutation coverage

## Tasks

### Phase 1: Baseline

- [ ] ⬜ **Task 1.1**: Record the honest per-directory MSI and the escaped mutants list from the first complete nightly run
- [ ] ⬜ **Task 1.2**: Re-baseline the provisional 89/89 floor in `qaConfig/qa.php` from that first complete nightly MSI

### Phase 2: Climb

- [ ] ⬜ **Task 2.1**: Kill escaped mutants directory by directory, raising the floor after each batch to 90
- [ ] ⬜ **Task 2.2**: Raise line and method coverage floors as coverage improves

### Phase 3: Upstream

- [ ] ⬜ **Task 3.1**: Propose to php-qa-ci that a high skipped-mutant ratio fails the Infection lane

## Success Criteria

- [ ] Floors in `qaConfig/qa.php` read 90 or higher for both mutation scores
- [ ] Full unfiltered `CI=true vendor/bin/qa` exits 0

## Delivery & Milestones

- Pending
