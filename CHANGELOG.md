# Changelog

All notable changes to phpxq are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The `VERSION` file is the single source of truth for the
current version.

Nobody edits version sections by hand. Changes are recorded under `## Unreleased`, using only the
headings `Changed — breaking`, `Removed`, `Added`, `Changed`, `Deprecated`, `Fixed` and `Security`
(the `changelog` lane of `vendor/bin/qa` enforces the shape and that every change to a shipped path has
an entry). The release workflow reads those headings to choose the next version, then moves the entries
into a `## X.Y.Z — date` section. The rules are in [docs/RELEASING.md](docs/RELEASING.md).

## Unreleased

### Fixed

- `yq`: reading a long YAML line that contains a non-ASCII character is linear again. A 110 KB single-line
  flow map with one `é` took 13 s and now takes about 1.5 s; a 360 KB one no longer runs past a minute.
- `jq`: `indices`, `index` and `rindex` on a non-ASCII string are linear in the number of matches.
  `"é" * 20000 | indices("é")` took 8 s and now takes 0.3 s.
- `jq`: slicing a non-ASCII string (`$s[$i:$j]`) no longer splits the whole string into characters on
  every slice. Taking every one-character slice of an 8,000-character string took about 20 s and now takes
  under 2 s.
- `jq`: `add` over objects and object `+` and `*` build the result in one pass instead of copying it once
  per key. `[range(80000) | {(tostring): .}] | add` took 70 s and now takes under 3 s; `{} + $o` on a
  20,000-key object went from 4 s to 0.2 s, and on 160,000 keys runs in under a second.

## 0.1.0 — 2026-10-08

### Changed — breaking

- **The PHAR and source install need PHP 8.5 with `ext-ctype`, `ext-json` and `ext-mbstring`.** They are
  declared in `composer.json`; the static binaries bundle them, so only the PHAR needs them on the host.

### Added

- Xdebug is switched off by default: when it is loaded with an active mode, `bin/phpxq` re-runs itself with
  `XDEBUG_MODE=off` (it needs `ext-pcntl`; without it the run carries on as is). Set `PHPXQ_ALLOW_XDEBUG=1`
  to keep it on. `yq` accepts YAML nested up to go-yaml's 10,000 levels, or 5,000 while Xdebug is on.
- `jq`: a pure-PHP implementation of jq 1.8.2. The full filter language and builtin
  library as exercised by the upstream test suite, modules, regular expressions, date functions and the
  command-line surface (`--stream`, `--seq`, `--slurp`, `--raw-input`, `--arg`/`--args`/`--jsonargs`,
  `--tab`/`--indent`, `-e` exit status and more).
- `jq`: standard input is processed as it arrives (line-delimited JSON and `-R` lines are emitted as soon
  as each line is read), so `tail -f log | jq .` works; a read error on standard input is reported like an
  unreadable file; with repeated `--arg`/`--argjson`/`--slurpfile`/`--rawfile` of the same name the first
  one wins; a closed output pipe (`jq . big.json | head`) ends the run silently with status 141.
- `yq`: a pure-PHP implementation of mikefarah/yq v4.54.1: YAML, JSON, XML, CSV, TSV, properties,
  TOML, HCL, INI, Lua, base64 and URI formats, anchors and aliases, comments preserved, multi-document streams, in-place
  editing, `eval` and `eval-all`, front-matter handling, and the yq operator set.
- A single `phpxq` executable that dispatches busybox style: `phpxq jq ...`, `phpxq yq ...`, or the
  program name itself (`jq`, `yq`) when invoked through a link.
- `phpxq --version` prints `phpxq X.Y.Z` followed by the jq and yq releases the tools are compatible
  with. `jq --version` and `yq --version` print what upstream prints.
- Release artefacts: a reproducible PHAR, static binaries (no PHP needed) for Linux x86_64 and aarch64
  and, best effort, macOS, `SHA256SUMS`, and an `install.sh` that verifies checksums.
- An automated release flow: a release pull request opened from the changelog, the next SemVer chosen from
  the `## Unreleased` headings, a tag and GitHub Release published from the `release` branch, and a
  back-merge into `main`. See `docs/RELEASING.md`.
- Conformance harness: the upstream jq and yq test suites (including the jq `shtest` and the yq
  acceptance scripts) run against the implementation, gated in CI with an explicit list of justified
  known gaps.
- Benchmark suite (`scripts/bench/bench.bash`).
- Known gaps in conformance, each enforced (it fails the build if it starts to pass or if an unlisted
  case fails; the full, justified lists are `tests/Conformance/Jq/known-gaps.txt` and
  `tests/Conformance/Yq/known-gaps.txt`):
  - jq: one upstream case (`jq.test:2337`). The upstream test runner has no input callback, so `input`
    raises "break" there, whereas the CLI reads stdin. The behaviour is correct from the command line.
  - yq: ten upstream documentation examples. Three assert a frozen clock (`now`, `to_unix`, a timezone
    conversion), one pins a Go `math/rand` shuffle sequence, the `system` operator is deliberately
    unsupported (it spawns processes), one decodes without yq's header preprocessing, and two upstream
    fixtures are damaged (base64 and base64url expected output swallowed trailing markdown).
