# phpxq: jq and yq in pure PHP

phpxq is a command-line equivalent of [jq](https://jqlang.github.io/jq/) (JSON) and
[yq](https://github.com/mikefarah/yq) (YAML and friends), written in pure PHP 8.5 with no production
dependencies. One executable provides both tools:

```bash
echo '{"a":[1,2,3]}' | phpxq jq '.a | map(. * 2)'
phpxq yq '.spec.replicas = 3' deployment.yaml
```

It targets **jq 1.8.2** and **mikefarah/yq v4.54.1**. The goal is equivalence, not invention: where
jq or yq defines a behaviour, phpxq matches it, and the upstream test suites are run against it to prove
that.

## Goals

- **Equivalence, not invention.** No new functionality, no new query language.
- **Fast.** A PHP CLI that starts quickly (about 50 ms for a small filter from the PHAR) and processes
  input as efficiently as PHP allows.
- **Verified against upstream.** The upstream jq and yq test suites run in CI, with every known gap
  listed and justified.
- **No production dependencies.** `composer.json` declares the PHP version and required extensions
  (`ext-ctype`, `ext-json`, `ext-mbstring`) and nothing else.

## Install

### Release binary (no PHP needed)

Releases ship static binaries for Linux (x86_64, aarch64) and, best effort, macOS, plus a PHAR and a
`SHA256SUMS` file. The installer verifies the SHA-256 checksum before installing anything:

```bash
curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh
# also create jq and yq links (they would shadow a real jq or yq, so this is opt-in):
curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh -s -- --links
```

Options: `--version X.Y.Z`, `--dir DIR`, `--links`, `--phar` (see `install.sh --help`). You can also
download an asset by hand and check it with `sha256sum -c SHA256SUMS`.

### PHAR (needs PHP 8.5)

Download `phpxq.phar` from the releases page, make it executable and run it. It works under any name; a
link named `jq` or `yq` selects that tool.

```bash
chmod +x phpxq.phar && ./phpxq.phar jq --version
```

### Composer

Needs PHP 8.5 with `ctype`, `json` and `mbstring`.

```bash
composer global require lts/phpxq     # once listed on Packagist
# or straight from the repository:
composer global config repositories.phpxq vcs https://github.com/LongTermSupport/php-xq
composer global require lts/phpxq:dev-main
```

Composer places `phpxq` in its global `bin` directory.

### From a checkout

```bash
git clone https://github.com/LongTermSupport/php-xq && cd php-xq
composer install --no-dev
bin/phpxq jq --version
```

## Usage

The executable dispatches on its name, busybox style. Either name the tool as the first argument or
call it through a link named `jq` or `yq`:

```bash
phpxq jq '.items[] | select(.ok)' data.json
phpxq yq -o=json '.' config.yaml

ln -s "$(command -v phpxq)" ~/bin/jq    # now `jq ...` is phpxq's jq
ln -s "$(command -v phpxq)" ~/bin/yq
```

### jq

Takes the same filters, options, exit codes and error messages as jq 1.8.2:

```bash
echo '{"name":"phpxq","tags":["a","b"]}' | jq -r '.name, (.tags | join(","))'
jq -n '[range(5)] | map(select(. % 2 == 0))'
jq --stream -c . big.json
jq -e '.ready' status.json || echo "not ready"      # exit status 1 when the result is false or null
jq --arg who world '"hello \($who)"' -n
```

### yq

Follows mikefarah/yq v4 (`eval` is the default command, `eval-all` loads every document first):

```bash
yq '.metadata.name' pod.yaml
yq -i '.spec.replicas = 3' deployment.yaml          # edit in place, comments preserved
yq -o=json '.' config.yaml                           # YAML to JSON
yq -p=json -o=yaml '.' data.json                     # JSON to YAML
yq eval-all 'select(fileIndex == 0) * select(fileIndex == 1)' a.yaml b.yaml
yq -o=csv '.[] | [.name, .age]' people.yaml
```

Formats: YAML, JSON, XML, CSV, TSV, properties, TOML, HCL, INI, Lua, base64 and URI (as in yq 4.54.1;
`yq --help` lists the flags).

### Version

```console
$ phpxq --version
phpxq 0.1.0
jq-1.8.2 compatible (jq)
yq v4.54.1 compatible (yq)
$ jq --version        # through a link named jq
jq-1.8.2
$ yq --version        # through a link named yq
yq (https://github.com/mikefarah/yq/) version v4.54.1
```

## Differences from upstream and known gaps

phpxq passes every upstream test it can: jq 878 of 879 cases and yq 564 of 574, and all of the
upstream shell suites. The remainder are deliberate, justified, and enforced (a gap that starts passing
or an unlisted failure breaks the build):

- [jq known gaps](tests/Conformance/Jq/known-gaps.txt): one case, an artefact of the upstream test
  runner (it has no `input` callback), not of the CLI.
- [yq known gaps](tests/Conformance/Yq/known-gaps.txt): ten documentation examples. Three depend on a
  frozen clock, one on Go's seeded `math/rand`, the `system` operator is intentionally unsupported
  (it spawns processes), and the rest are an upstream header-preprocessing quirk and two damaged upstream
  fixtures.

Other differences you may notice:

- `phpxq --version` is an addition; `jq --version` and `yq --version` print exactly what upstream prints.
- Performance characteristics differ from the native tools: phpxq is a PHP program. See the benchmarks
  below.

## Development

The project is developed in a [CCY](https://github.com/LongTermSupport/fedora-desktop) container; the
PHP 8.5 environment is defined in `.claude/ccy/Dockerfile`.

```bash
composer install                                      # dev dependencies (lts/php-qa-ci, PHPUnit)
vendor/bin/qa                                         # full QA pipeline
vendor/bin/phpunit -c qaConfig/phpunit.xml --no-coverage   # unit tests
```

The project has no production dependencies, so php-qa-ci's Safe-function Rector lane and its
`thecodingmachine/safe` require-checker scan files are overridden in `qaConfig/`, and the
`#[\SensitiveParameter]` check is disabled in `qaConfig/qa.php`.

### Conformance

`scripts/conformance.bash [jq|yq|all]` runs the vendored upstream suites (the jq `.test` files, the
jq `shtest` shell driver, the yq documentation examples and acceptance scripts) and checks the results
against `tests/Conformance/<Tool>/known-gaps.txt`. It prints `CONFORMANCE: OK` on success and exits
non-zero on an unexpected failure or on a known gap that now passes. The same suites are available as
PHPUnit suites (`--testsuite jq`, `--testsuite yq`), and `PHPXQ_BINARY=dist/phpxq-linux-x86_64 scripts/conformance-shell.bash all` runs the shell suites against a built binary.

Vendored fixtures keep their own licences: see
[tests/Conformance/Jq/fixtures/NOTICE.md](tests/Conformance/Jq/fixtures/NOTICE.md) and
[COPYING](tests/Conformance/Jq/fixtures/COPYING) (jq, MIT), and
[tests/Conformance/Yq/fixtures/NOTICE.md](tests/Conformance/Yq/fixtures/NOTICE.md) and
[LICENSE](tests/Conformance/Yq/fixtures/LICENSE) (yq, MIT).
`scripts/refresh-upstream-fixtures.bash` re-fetches them from the pinned upstream tags.

### Benchmarks

`scripts/bench/bench.bash` measures startup time and throughput (small, medium and large JSON and YAML,
representative filters) for phpxq and, when installed, the real `jq` and mikefarah `yq`.

```bash
scripts/bench/bench.bash run                     # JSON + Markdown report under untracked/bench/
scripts/bench/bench.bash baseline --label NAME   # store benchmarks/baselines/NAME.json
scripts/bench/bench.bash run --compare benchmarks/baselines/NAME.json
```

Inputs are generated deterministically and never committed. Results record the PHP, OPcache/JIT, CPU and
kernel configuration; only compare results taken on the same machine. Methodology:
`CLAUDE/Plan/00005-benchmarking-suite/BENCHMARKS.md`.

### Claude Code hooks

This repository uses the
[Claude Code Hooks Daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon) for
deterministic guardrails on agent tool calls; configuration lives in `.claude/hooks-daemon.yaml`.

## Releasing

A release is a pull request from `main` into the `release` branch; merging it runs the release workflow,
which runs the full QA gate and conformance suites, builds and smoke-tests the PHAR and static binaries,
tags `vX.Y.Z` and publishes the GitHub Release. The version lives in the `VERSION` file. The steps and
the one-off GitHub settings are in [docs/RELEASING.md](docs/RELEASING.md); the history is in
[CHANGELOG.md](CHANGELOG.md).

## Licence

phpxq is released under the [MIT licence](LICENSE). Vendored upstream test fixtures are covered by
their own licences, noted above.
