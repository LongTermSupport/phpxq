# jq/yq in pure PHP

Command-line equivalents of [jq](https://jqlang.github.io/jq/) and
[yq](https://github.com/mikefarah/yq), written in pure PHP 8.5.

## Goals

- **Equivalence, not invention.** Follow the jq / yq paradigm as closely as
  possible. No new functionality, no new query language: where jq or yq has a
  defined behaviour, we match it.
- **Fast.** A PHP CLI that starts quickly and processes input as efficiently as
  PHP allows.
- **Verified against upstream.** Where feasible, run the upstream jq and yq test
  suites against this implementation to prove compatibility.
- **No production dependencies.** Pure PHP; `composer.json` exists to declare the
  PHP version and required extensions, to make the tool installable, and to track
  dev dependencies only.

## Status

Early setup. Tooling is in place; no jq/yq functionality has been implemented yet.

## Requirements

- PHP 8.5

## Development

The project is developed in a [CCY](https://github.com/LongTermSupport/fedora-desktop)
container. The PHP 8.5 environment is defined in `.claude/ccy/Dockerfile`; rebuild
it with `ccy --rebuild` after changing it.

```bash
composer install     # dev dependencies only (PHP QA CI)
```

## Claude Code hooks

This repository uses the
[Claude Code Hooks Daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon)
for deterministic guardrails on agent tool calls. Configuration lives in
`.claude/hooks-daemon.yaml`.
