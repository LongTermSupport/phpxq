# phpxq: jq/yq in pure PHP

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

- PHP 8.5 to run from source or the PHAR; nothing for the static binaries.

## Install

Releases ship static binaries for Linux (x86_64, aarch64) and macOS, plus a PHAR. The installer
verifies the SHA-256 checksum before installing anything:

```bash
curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh
# also create jq and yq links (they would shadow a real jq or yq, so this is opt-in):
curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh -s -- --links
```

The binary dispatches on its name, busybox style: `phpxq jq ...`, or just `jq ...` when invoked
through a link named `jq` (likewise `yq`). `phpxq --version` reports the release. Releases are cut
as described in [docs/RELEASING.md](docs/RELEASING.md).

## Development

The project is developed in a [CCY](https://github.com/LongTermSupport/fedora-desktop)
container. The PHP 8.5 environment is defined in `.claude/ccy/Dockerfile`; rebuild
it with `ccy --rebuild` after changing it.

```bash
composer install     # dev dependencies only (lts/php-qa-ci)
vendor/bin/qa        # full QA pipeline
```

### Test suites

`qaConfig/phpunit.xml` defines three suites. The default suite, `unit`, is what `vendor/bin/qa`
runs. The upstream conformance suites, `jq` and `yq`, run the vendored upstream test cases
against the front controller (`bin/phpxq jq ...` / `bin/phpxq yq ...`). They are red until each
tool is implemented, so they are not part of the default run; add them to `defaultTestSuite`
in `qaConfig/phpunit.xml` once they are ready to gate.

```bash
vendor/bin/phpunit -c qaConfig/phpunit.xml                    # default suite (unit)
vendor/bin/phpunit -c qaConfig/phpunit.xml --testsuite jq     # upstream jq conformance
vendor/bin/phpunit -c qaConfig/phpunit.xml --testsuite yq     # upstream yq conformance
```

The vendored fixtures record their upstream tag, commit and licence in a `NOTICE.md` beside them.
`scripts/refresh-upstream-fixtures.bash` re-fetches them from the pinned tags.

`scripts/conformance.bash [jq|yq|all]` runs everything, including the upstream shell suites (jq
`shtest`, yq acceptance scripts), and checks the results against each tool's
`tests/Conformance/<Tool>/known-gaps.txt`. It exits non-zero on an unexpected failure or on a known
gap that now passes, so the gap lists shrink as the tools are built.

### Benchmarks

`scripts/bench/bench.bash` measures startup time and throughput (small, medium, large JSON and YAML,
representative filters) for phpxq and, when installed, the real `jq` and mikefarah `yq`. Tools phpxq has
not implemented yet are recorded as `not-implemented`; a missing reference tool as `unavailable`.

```bash
scripts/bench/bench.bash run                     # JSON + Markdown report under untracked/bench/
scripts/bench/bench.bash baseline --label NAME   # store benchmarks/baselines/NAME.json
scripts/bench/bench.bash run --compare benchmarks/baselines/NAME.json
```

Inputs are generated deterministically and never committed. Results record the PHP, OPcache/JIT, CPU and
kernel configuration; only compare results taken on the same machine. Methodology and result format:
`CLAUDE/Plan/00005-benchmarking-suite/BENCHMARKS.md`.

The project has no production dependencies, so php-qa-ci's Safe-function Rector
lane and its `thecodingmachine/safe` require-checker scan files are overridden in
`qaConfig/`, and the `#[\SensitiveParameter]` check is disabled in `qaConfig/qa.php`.

## Claude Code hooks

This repository uses the
[Claude Code Hooks Daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon)
for deterministic guardrails on agent tool calls. Configuration lives in
`.claude/hooks-daemon.yaml`.
