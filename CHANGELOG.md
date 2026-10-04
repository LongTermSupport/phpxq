# Changelog

All notable changes to phpxq are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The `VERSION` file is the single source of truth for the
current version.

## [Unreleased]

### Changed

- `jq`: standard input is processed as it arrives (line-delimited JSON and `-R` lines are emitted as soon
  as each line is read) instead of after end-of-file, so `tail -f log | jq .` works; a read error on
  standard input (a directory, say) is reported like an unreadable file.
- `jq`: with repeated `--arg`/`--argjson`/`--slurpfile`/`--rawfile` of the same name the first one wins,
  as in jq.
- `jq`: a closed output pipe (`jq . big.json | head`) ends the run silently with status 141, as jq does
  when SIGPIPE kills it, instead of printing "writing output failed".

## [0.1.0]

First release.

### Added

- `jq`: a pure-PHP implementation of jq 1.8.2. The full filter language and builtin
  library as exercised by the upstream test suite, modules, regular expressions, date functions and the
  command-line surface (`--stream`, `--seq`, `--slurp`, `--raw-input`, `--arg`/`--args`/`--jsonargs`,
  `--tab`/`--indent`, `-e` exit status and more).
- `yq`: a pure-PHP implementation of mikefarah/yq v4.54.1: YAML, JSON, XML, CSV, TSV, properties,
  TOML, HCL, INI, Lua, base64 and URI formats, anchors and aliases, comments preserved, multi-document streams, in-place
  editing, `eval` and `eval-all`, front-matter handling, and the yq operator set.
- A single `phpxq` executable that dispatches busybox style: `phpxq jq ...`, `phpxq yq ...`, or the
  program name itself (`jq`, `yq`) when invoked through a link.
- `phpxq --version` prints `phpxq X.Y.Z` followed by the jq and yq releases the tools are compatible
  with. `jq --version` and `yq --version` print what upstream prints.
- Release artefacts: a reproducible PHAR, static binaries (no PHP needed) for Linux x86_64 and aarch64
  and, best effort, macOS, `SHA256SUMS`, and an `install.sh` that verifies checksums.
- Conformance harness: the upstream jq and yq test suites (including the jq `shtest` and the yq
  acceptance scripts) run against the implementation, gated in CI with an explicit list of justified
  known gaps.
- Benchmark suite (`scripts/bench/bench.bash`).

### Known gaps

Each gap is enforced: it fails the build if it starts to pass or if an unlisted case fails. The full,
justified lists are `tests/Conformance/Jq/known-gaps.txt` and `tests/Conformance/Yq/known-gaps.txt`.

- jq: one upstream case (`jq.test:2337`). The upstream test runner has no input callback, so `input`
  raises "break" there, whereas the CLI reads stdin. The behaviour is correct from the command line.
- yq: ten upstream documentation examples. Three assert a frozen clock (`now`, `to_unix`, a timezone
  conversion), one pins a Go `math/rand` shuffle sequence, the `system` operator is deliberately
  unsupported (it spawns processes), one decodes without yq's header preprocessing, and two upstream
  fixtures are damaged (base64 and base64url expected output swallowed trailing markdown).

[0.1.0]: https://github.com/LongTermSupport/phpxq/releases/tag/v0.1.0
[unreleased]: https://github.com/LongTermSupport/phpxq/compare/v0.1.0...HEAD
