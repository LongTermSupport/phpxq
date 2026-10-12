# Plan 00010: full code review opus vs haiku

**Status**: Complete (PR #13 and PR #15)
**Created**: 2026-10-08
**Owner**: dev
**Priority**: High

## Overview

Full repository review after the v0.1.0 release, covering code quality, performance and security, plus an audit of the QA configuration for strictness and for cheating (baselines, suppression comments, loosened assertions, coverage games). Leftover clutter such as stray `.gitkeep` files is removed.

The same brief is given to two independent read-only reviewers: Opus at high effort and Haiku 5.5. Each writes its own report into this folder (`REVIEW-opus.md`, `REVIEW-haiku.md`) so the two can be compared for depth, precision and false positives. Confirmed findings are fixed through a `chore/` or `bugfix/` branch and pull request.

## Goals

- Two review reports in this folder, suffixed `-opus` and `-haiku`
- A comparison note (`COMPARISON.md`) of the two reports
- No stray placeholder files in directories that already hold content
- QA config verified at its strictest feasible setting, with no baselines or suppressions
- Every confirmed finding fixed or explicitly tracked

## Non-Goals

- New features
- Loosening any QA gate

## Tasks

### Phase 1: Reviews

- [x] ✅ **Task 1.1**: Opus high-effort review written to `REVIEW-opus.md`
- [x] ✅ **Task 1.2**: Haiku 5.5 review written to `REVIEW-haiku.md`
- [x] ✅ **Task 1.3**: Compare the reports in `COMPARISON.md`

### Phase 2: Cleanup and audit

- [x] ✅ **Task 2.1**: Remove redundant `.gitkeep` files and other clutter
- [x] ✅ **Task 2.2**: Audit QA config strictness and suppression or baseline usage

### Phase 3: Fixes

- [x] ✅ **Task 3.1**: Fix confirmed findings on a branch, full pipeline green, PR merged (PR #13, then the cosmetic pass in PR #15)

## Success Criteria

- [x] Both reports and the comparison exist in this folder
- [x] Full unfiltered `CI=true vendor/bin/qa` exits 0 on the fix branch
- [x] Fix PR merged with a merge commit

## Delivery & Milestones

- Delivered in PR #13 and PR #15 (merge commits on `main`)
