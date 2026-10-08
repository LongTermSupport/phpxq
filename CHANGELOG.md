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

- `yq`: without `--yaml-fix-merge-anchor-to-spec`, the output formats still merge only aliases of mappings
  and leave inline merge sources out, as the reference does, but a merge key whose alias points at a
  sequence or a scalar (`<<: *list`) is now the reference's error `can only use merge anchors with maps (!!map) or sequences (!!seq) of maps, ...` instead of being silently dropped.

- `yq`: merge keys are recognised as the reference recognises them for each use. Lookups (`.a`, `.[]`) follow
  a key tagged `!!merge`, which a plain `<<` is, and no longer a `<<` with another tag (`!!str <<`, `!x <<`);
  a merge key is still found, read and deleted by its own text (`."<<"`, `.foo` on `!!merge foo`).
  `.[]` and `.*` follow every key tagged `!!merge`, whatever its name, so `{!!merge foo: {x: 5}, x: 7}`
  yields `7` once; the other listings (`to_entries`, `with_entries`, `map`, sorting) merge only through a
  `<<` tagged `!!merge`, as before. With `--yaml-fix-merge-anchor-to-spec`, a mapping holding several merge
  keys takes them from the last back, so the earliest one's values win.
  Without `--yaml-fix-merge-anchor-to-spec`, the output formats and `explode` merge through any `<<` key,
  quoted or tagged, as the reference does; with it, only through a `<<` tagged `!!merge`.

### Fixed

- `yq --yaml-fix-merge-anchor-to-spec`: the output formats now merge what navigation merges. A `<<` merge
  key whose value is an inline mapping (`<<: {a: 1}`), a sequence holding inline mappings, or an alias of a
  sequence was honoured by `.a` but dropped by `-o json` and the other encoders, and by format operators
  such as `@json`, which now also follow the flag.
- `yq`: a lookup through a chain of more than 32 mappings that each merge the next (`a1: {<<: *a0}`,
  `a2: {<<: *a1}`, ...) silently lost the keys beyond the 32nd; chains are now followed as deep as a
  document may nest.
- `yq`: when the regex engine gives up (the backtracking limit, or a malformed UTF-8 string such as one from
  `@base64d`), `test`, `match`, `capture`, `sub` and `*` wildcards in `==` and key lookups now raise an error,
  as `jq` does, instead of silently answering false, no match or the unchanged input.

### Security

- `yq`: a merge key that merges the mapping it sits in (`a: &a {x: 1, <<: *a}`) no longer crashes the
  process with a segmentation fault in the JSON, properties, TOML, Lua, shell, HCL, XML and KYAML encoders
  or in `explode` with `--yaml-fix-merge-anchor-to-spec`; the re-entered mapping counts as already merged.
- `yq`: `explode` of an alias or merge key that refers to a node containing it (`a: &a [*a, *a]`,
  `a: &a {b: {<<: *a}}`) copied it level upon level until time ran out, in either merge mode; it is now
  refused at once with `cannot explode: an alias or merge key refers to a node that contains it`.
- `yq`: an alias bomb (a few hundred bytes of nested aliases that expand to billions of nodes) is refused with
  `document contains excessive aliasing`, as go-yaml words it, by every output format that writes aliases out
  as copies, by format operators such as `@json` and by `explode`, instead of running until time or memory
  ran out. A document is refused when it would write out more than four million nodes, whatever its own size
  (padding a bomb with plain items does not raise the limit), or more than a million nodes at more than a
  thousand times its own size. Templates that merge a shared base into thousands of items are written as
  before. A `<<` key counts as a merge exactly when the writer in that merge mode merges through it, so a
  tagged key (`!x <<`) cannot hide a bomb. YAML output keeps the aliases and is unaffected.
- `yq`: a mapping merged along many paths (`<<: [*a, *a, ...]`, level upon level) is expanded once per lookup,
  so `.key` lookups, `.[]` and the encoders take linear rather than exponential time over such merge chains;
  the alias budget above counts a repeated merge source once.
- `yq --split-exp`: file names come from the data, so a name must now be a plain path inside the current
  directory. A stream wrapper (`php://filter/...`, `file:///...`, `ftp://...`, `data:...`), a NUL byte, or a
  path that climbs out of the directory (`../x`, an absolute path elsewhere, or through a symlink that already
  exists there) is refused before anything is created. `.` and `..` inside the name are resolved, so
  `sub/../x` now writes `x` instead of failing.
- `yq -o xml`: a key, comment or directive could write markup into the output (`"x><evil/><y": 1`,
  `# c --> <evil/>`, `+directive: "DOCTYPE x><evil/><y"`). Element and attribute names may now not be
  empty or hold any of `< > & " ' = / ! ?`, processing-instruction targets must be XML names, a comment may
  not contain `-->`, a processing instruction may not contain `?>`, and a directive's `<` and `>` must
  balance outside quotes and comments, as the reference checks; each is a format error, worded as the
  reference words it where it refuses the same thing. Names the reference writes as they are, such as `200`
  or `my key`, are still written.

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
