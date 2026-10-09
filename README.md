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

## Quick start

1. **Install** a release binary (no PHP needed; see [Install](#install) for the other ways):

   ```bash
   curl -fsSL https://github.com/LongTermSupport/phpxq/releases/latest/download/install.sh | sh
   ```

2. **Query JSON** with the jq language:

   ```bash
   curl -s https://api.github.com/repos/LongTermSupport/phpxq | phpxq jq -r '.full_name, .default_branch'
   ```

3. **Query and edit YAML** with the yq language:

   ```bash
   phpxq yq '.services | keys' docker-compose.yml
   phpxq yq -i '.image.tag = "v2"' values.yaml
   ```

If you already know jq or yq you already know phpxq: the filters, flags, exit codes and error messages are
the same ([usage](#usage), [differences](#differences-from-upstream-and-known-gaps)).

## Goals

- **Equivalence, not invention.** No new functionality, no new query language.
- **Fast.** A PHP CLI that starts quickly (about 70 ms for a small filter from the PHAR, about 30 ms from
  the static binary) and processes input as efficiently as PHP allows.
- **Verified against upstream.** The upstream jq and yq test suites run in CI, with every known gap
  listed and justified.
- **No production dependencies.** `composer.json` declares the PHP version and required extensions
  (`ext-ctype`, `ext-json`, `ext-mbstring`) and nothing else.

## Install

### Release binary (no PHP needed)

Releases ship static binaries for Linux (x86_64, aarch64) and, best effort, macOS, plus a PHAR and a
`SHA256SUMS` file. The installer verifies the SHA-256 checksum before installing anything:

```bash
curl -fsSL https://github.com/LongTermSupport/phpxq/releases/latest/download/install.sh | sh
# also create jq and yq links (they would shadow a real jq or yq, so this is opt-in):
curl -fsSL https://github.com/LongTermSupport/phpxq/releases/latest/download/install.sh | sh -s -- --links
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
composer global require lts/phpxq
# or the development version straight from the repository:
composer global config repositories.phpxq vcs https://github.com/LongTermSupport/phpxq
composer global require lts/phpxq:dev-main
```

The package is on Packagist as [lts/phpxq](https://packagist.org/packages/lts/phpxq).

Composer places `phpxq` in its global `bin` directory.

### From a checkout

```bash
git clone https://github.com/LongTermSupport/phpxq && cd phpxq
composer install --no-dev
bin/phpxq jq --version
```

## Use as a library

The Composer package can be required by another project and called from PHP code, without spawning a process:

```php
$names = LTS\PhpXq\Jq\Jq::run('.items[] | .name', (new LTS\PhpXq\Json\JsonDecoder())->decodeOne($json));
$yaml  = LTS\PhpXq\Yq\Yq::evaluate('.items[0].n = 10', $yamlText);
```

[docs/LIBRARY.md](docs/LIBRARY.md) lists the supported public classes (everything else is internal and may
change), with executed examples for JSON, jq, YAML and yq, the exceptions raised, and the memory limits.

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

Formats: YAML, JSON, XML, CSV, TSV, properties, TOML, HCL, INI, Lua, base64 and URI, plus shell and KYAML
as output formats (as in yq 4.54.1; `yq --help` lists the flags).

### Common tasks

| I want to...                         | Command                                                                |
| ------------------------------------ | ---------------------------------------------------------------------- |
| pretty-print JSON                    | `phpxq jq . file.json`                                                 |
| compact JSON onto one line           | `phpxq jq -c . file.json`                                              |
| pick a field as plain text           | `phpxq jq -r '.user.name' file.json`                                   |
| filter an array                      | `phpxq jq '[.[] \| select(.age > 30)]' file.json`                      |
| use a shell variable in a filter     | `phpxq jq --arg id "$ID" '.[] \| select(.id == $id)' file.json`        |
| fail a script on a false/null result | `phpxq jq -e '.ready' file.json`                                       |
| read a value out of YAML             | `phpxq yq '.spec.template.spec.containers[0].image' deploy.yaml`       |
| change a value, keep the comments    | `phpxq yq -i '.spec.replicas = 3' deploy.yaml`                         |
| merge two YAML files                 | `phpxq yq eval-all '. as $item ireduce ({}; . * $item)' a.yaml b.yaml` |
| convert YAML to JSON and back        | `phpxq yq -o=json . a.yaml` / `phpxq yq -p=json -o=yaml . a.json`      |
| split a multi-document YAML file     | `phpxq yq -s '.metadata.name' manifests.yaml`                          |

Everything reads standard input when no file is given, so it works in pipelines
(`kubectl get pods -o json | phpxq jq -r '.items[].metadata.name'`).

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

phpxq passes every upstream test it can: jq 878 of 879 cases and yq 565 of 572 (two further yq examples,
whose expected output is a Go-seeded `shuffle`, are skipped because ours is random), and all of the
upstream shell suites. The remainder are deliberate, justified, and enforced (a gap that starts passing
or an unlisted failure breaks the build):

- [jq known gaps](tests/Conformance/Jq/known-gaps.txt): one case, an artefact of the upstream test
  runner (it has no `input` callback), not of the CLI.
- [yq known gaps](tests/Conformance/Yq/known-gaps.txt): seven documentation examples. Three depend on a
  frozen clock, two use the `system` operator, which is intentionally
  unsupported (it spawns processes), and two are damaged upstream fixtures.

Other differences you may notice:

- `phpxq --version` is an addition; `jq --version` and `yq --version` print exactly what upstream prints.
- phpxq is a PHP program, so it starts slower than the native tools; see [Performance](#performance).

## Performance

phpxq is not a replacement for a native binary when startup time matters (a shell loop calling jq
thousands of times). It is competitive on larger inputs, where startup is a rounding error.

**What it costs to start.** PHP itself takes 45 to 56 ms of CPU just to launch, so a trivial filter is
about 70 ms from a checkout or the PHAR and about 30 ms from the static binary, against about 40 ms
(jq 1.6) for the native jq.

**Xdebug.** It slows every PHP call, so when it is loaded with an active mode `bin/phpxq` re-runs itself
with `XDEBUG_MODE=off` (this needs `ext-pcntl`; without it the run carries on as is). Set
`PHPXQ_ALLOW_XDEBUG=1` to keep it on, for example to debug phpxq itself. With Xdebug kept on, `yq` accepts
YAML nested up to 5,000 levels instead of 10,000, because Xdebug uses far more native stack per call.

**jq throughput** (CPU milliseconds, lower is better; minimum of 3 runs; reference is jq 1.6, the
version available on the benchmark host):

| workload                    | phpxq jq | jq 1.6 |
| --------------------------- | -------: | -----: |
| startup                     |       71 |     40 |
| identity on a medium file   |      133 |    134 |
| `group_by` on a medium file |      136 |    119 |
| identity on a large file    |    1,436 |  1,926 |
| aggregate on a large file   |      741 |  1,926 |
| `group_by` on a large file  |    1,455 |  1,851 |

**yq throughput** (CPU milliseconds; minimum of 3 runs; production-like build):

| workload                    | phpxq yq |
| --------------------------- | -------: |
| startup                     |       56 |
| identity on a medium file   |      333 |
| `select` on a medium file   |      273 |
| `group_by` on a medium file |      342 |
| YAML to JSON, medium file   |      317 |
| identity on a large file    |    6,417 |
| `select` on a large file    |    4,852 |

On medium YAML files a wall-clock comparison against mikefarah yq v4.54.1 on the same (busy) host put
phpxq between 0.64x and 0.96x of its time; startup and many-small-files workloads are 2 to 5x slower.

How to read these numbers: they come from a shared, loaded machine, so only CPU time (user plus system,
minimum of several runs) is reported, and differences under about 5 percent are noise. "Medium" is about
640 KB and "large" is tens of MB of generated data. Absolute numbers depend on your hardware; run the
benchmark suite below to measure your own. The full results, what each optimisation bought, and the ones
that were tried and rejected are in
[CLAUDE/Plan/Completed/00007-performance-optimisation-round](CLAUDE/Plan/Completed/00007-performance-optimisation-round/)
(`results.md`, `results-yq.md`, `results-binary.md`, `hot-spots.md`).

## Development

The project is developed in a [CCY](https://github.com/LongTermSupport/fedora-desktop) container; the
PHP 8.5 environment is defined in `.claude/ccy/Dockerfile`.

```bash
composer install                                      # dev dependencies (lts/php-qa-ci, PHPUnit)
scripts/qa-scoped.bash                                # QA pipeline, mutation testing only on what changed since origin/main
vendor/bin/qa                                         # full QA pipeline, mutating all of src/ (over an hour; needs Xdebug)
scripts/check-qa-measurements.bash                    # after a full run: coverage and mutation ran, floors met
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

`scripts/bench/bench.bash` measures startup time and throughput (small and medium JSON and YAML by
default, large with `--sizes`; representative filters) for phpxq and, when installed, the real `jq` and mikefarah `yq`.

```bash
scripts/bench/bench.bash run                     # JSON + Markdown report under untracked/bench/
scripts/bench/bench.bash baseline --label NAME   # store benchmarks/baselines/NAME.json
scripts/bench/bench.bash run --compare benchmarks/baselines/NAME.json
```

Inputs are generated deterministically and never committed. Results record the PHP, OPcache/JIT, CPU and
kernel configuration; only compare results taken on the same machine. Methodology:
`CLAUDE/Plan/Completed/00005-benchmarking-suite/BENCHMARKS.md`.

### Defence Before Fix

Bugs found in this project are handled with the
[Defence Before Fix](https://defence-before-fix.github.io/) method: a defect is attributed to its class,
a static-analysis rule that catches the whole class is built and proven to fire, every other instance is
found and fixed, and only then is the original bug fixed with a regression test. The rules, what each one
catches and how to run it are in [docs/defence-before-fix.md](docs/defence-before-fix.md) (`vendor/bin/rules`
lists every active defence, `vendor/bin/phpstan-rule <identifier> <path>` proves one on one path).

### Claude Code hooks

This repository uses the
[Claude Code Hooks Daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon) for
deterministic guardrails on agent tool calls; configuration lives in `.claude/hooks-daemon.yaml`.

## Releasing

Releases are cut from the changelog; nobody picks a version or tags by hand. Record every user-visible change
under `## Unreleased` in [CHANGELOG.md](CHANGELOG.md) (QA enforces it); the headings choose the next
[SemVer](https://semver.org/) version. When `main` is green and has unreleased entries, a bot opens a release
pull request into the `release` branch. Merging it runs the release workflow: full QA and conformance, the
PHAR and static binaries, smoke tests, the tag `vX.Y.Z` and the GitHub Release. A back-merge pull request then
brings `VERSION` and the changelog on `main` in line. The flow, the version rules and the one-off GitHub
settings are in [docs/RELEASING.md](docs/RELEASING.md).

## Sponsor

phpxq's development, including the AI tokens used to build it, is paid for by
[Edmonds Commerce](https://www.edmondscommerce.co.uk/), a digital agency that builds and maintains
e-commerce platforms and bespoke web applications. Thank you.

## Licence

phpxq is released under the [MIT licence](LICENSE). Vendored upstream test fixtures are covered by
their own licences, noted above.
