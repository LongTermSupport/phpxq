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

### Changed

- `yq`: string repetition follows Go yq: the count must be an `!!int` (`"ab" * 2.5` is now `cannot multiply !!str with !!float`), a negative count is an error, and the result may not exceed 10 MiB.

### Fixed

- `jq`: `until` and `while` run any number of iterations, as jq's tail-call optimisation lets them;
  `0 | until(. >= 30000; . + 1)` failed with `Evaluation too deep` after 20,000.
- `yq`: numbers too large for an integer no longer end the run with an `internal error`. A slice bound or
  integer argument that is not an integer is Go yq's error (`.[0:1e30]` and `.[0:1.5]` are
  `strconv.ParseInt: parsing "1e30": invalid syntax`; they used to be truncated), `1e30 | from_unix` is
  `cannot convert 1e30 to a unix time`, an XML character reference beyond Unicode
  (`&#x99999999999999999999;`) is kept literally, and a Lua `\u{...}` escape beyond Unicode or naming a
  surrogate is the error `invalid \u escape`.
- `jq`: a `/` in a regex conditional `(?(...)` or a group name no longer ends the pattern early with PHP's
  `Unknown modifier` message, and `(*...)` is read as an Oniguruma callout as jq reads it: `(*FAIL)` works, and
  PCRE verbs and options such as `(*ACCEPT)` or `(*LIMIT_MATCH=1)` are rejected (`undefined callout name`,
  `invalid callout name`) instead of changing how the pattern matches.
- `yq`: an escaped tilde in a regular expression (`test("\\~")`, `sub("\\~"; "-")`) is a literal `~`, as in
  Go yq, instead of the error `invalid or unsupported Perl syntax`, and a `~` inside `\Q...\E` matches.
- `jq`: the regex `l` (longest match) modifier takes linear time over the subject; with `g` it was
  quadratic (`[match("a"; "gl")]` over 4,000 characters took 12 seconds, 20,000 now take a fraction of one).

### Security

- `jq`: a program nested deeper than 10,000 levels (brackets, `|` or `//` operands, or nested constructs) is a
  compile error, `syntax error, program nested deeper than 10000 levels`, instead of a crash with a
  segmentation fault. Programs are now parsed and compiled on the evaluation stack. Chains are not nesting
  and have no limit: an array literal of 100,000 elements, which crashed, now runs.
- `yq`: an expression nested deeper than 10,000 levels, including one read from the data by `eval`, is the
  error `Bad expression, nested deeper than 10000 levels` instead of a segmentation fault, and nested string
  interpolations are parsed in linear time (10,000 levels took minutes before). Chains (`|`, `,`, `+`,
  `.a.b...`) are not nesting and have no limit, and expressions are evaluated on a large stack, so a chain of
  30,000 steps no longer crashes while a coverage driver is loaded.
- `jq`: invalid UTF-8 in `--arg`, `--args`, `--rawfile`, argument names and the program text is replaced
  with U+FFFD, as jq does. It used to reach `explode` and similar builtins and end the run with an
  uncatchable `internal error: Uninitialized string offset`.
- Small programs and inputs can no longer force huge allocations that end in an uncatchable out-of-memory
  error. Assigning to an array index more than 2^28 (268,435,456) places past the end is an error (`jq`:
  `Cannot pad array to index ...`; `yq`, including a properties key such as `a.999999999`:
  `cannot pad a sequence ...`), and jq refuses to repeat a string into more than 1 GiB
  (`Repeat string result too long`). Padding and repetition that jq 1.6 and Go yq perform
  (`null | .[2000000] = 1`, `"x" * 300000000`) still work.

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
