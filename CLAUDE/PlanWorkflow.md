# PlanWorkflow

**Read first:** [CLAUDE/core/PlanWorkflow.core.md](core/PlanWorkflow.core.md) — the daemon's core
guidance for this subject, and the baseline everything below extends.

That file is DAEMON-owned: it is overwritten wholesale on every
daemon upgrade, so never edit it and never copy its content here. A
second copy is how the two drift apart, which is the failure this
split exists to prevent.

## Project-specific additions

This file is yours. The daemon seeds it once and never modifies it
again, so anything you add below survives every upgrade.

### Plans versus GitHub issues

- **Plan folders are for agents.** All rich context lives in `CLAUDE/Plan/`: designs, decisions,
  research, journals.
- **GitHub issues are for humans**: top-level status tracking and overview only. Never use an
  issue as a journal, and never paste plan detail into one.
- Plan 00001 is the epic. One GitHub issue mirrors it (a status checklist of the sub-plans). When
  a sub-plan changes status, update that issue's checklist; do not add commentary.
- Sub-feature plans (jq, yq, benchmarking, packaging, optimisation) each get a plan, not an issue.

### Project priorities

- Equivalence with jq and yq: no new functionality.
- Speed beats elegance in hot paths, but only with a benchmark to justify it (Plan 00007).
