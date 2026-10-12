# Packaging notes (Plan 00006)

Supporting document for Tasks 1.1 and 1.2. The release process for owners is `docs/RELEASING.md`.

## Verified tool versions and commands

| Tool                 | Version | Source                                                                     |
| -------------------- | ------- | -------------------------------------------------------------------------- |
| Box                  | 4.7.0   | `box-project/box` release `box.phar`, SHA-256 pinned                       |
| static-php-cli (spc) | 2.8.5   | `crazywhalecc/static-php-cli` release tarball per platform, SHA-256 pinned |
| PHP in the runtime   | 8.5     | spc `--with-php=8.5` (its default at 2.8.5)                                |

Pins and checksums: `packaging/tools.env`. Commands, as run by `scripts/build-binary.bash`:

```text
spc doctor --auto-fix --no-interaction
spc download --for-extensions=<exts> --with-php=8.5 --prefer-pre-built --retry=3
spc build <exts> --build-micro
spc micro:combine dist/phpxq.phar --output=dist/phpxq-<platform> --with-ini-set=memory_limit=-1
```

## Technical decisions

- **Box is a pinned download, not a Composer dev dependency.** `composer require --dev humbug/box`
  does not resolve against php-qa-ci's current dependency set (Box needs older `nikic/php-parser` and
  `sebastian/diff` majors). The download is verified against a recorded SHA-256 and its reported
  version is checked.
- **Reproducible PHAR.** Built from a staging copy (`bin`, `src`, `VERSION`, `composer.*`) with
  `composer install --no-dev --classmap-authoritative`, a fixed alias (`phpxq.phar`; Box otherwise
  generates a random one), no compression, and the timestamp pinned to `SOURCE_DATE_EPOCH` (default:
  the HEAD commit time). `--check-reproducible` builds twice and compares bytes; CI requires it.
- **No PHAR compression.** Keeps the PHAR free of any `zlib` requirement on the user's PHP and the
  build deterministic. Size is small because the project has no production dependencies.
- **One entry point, three shapes.** `bin/phpxq` is the PHAR main script, the source entry and the
  program inside the static binary. `LTS\PhpXq\Cli\EntryPoint` does busybox-style dispatch: a program
  named `jq` or `yq` (symlink, copy, `.exe`) implies the tool; otherwise `phpxq jq|yq ...`.
  `phpxq --version` prints `phpxq <VERSION>`.
- **`--version` hook for the CLI owners.** `EntryPoint` answers `--version` only for the `phpxq`
  name. `jq --version` and `yq --version` reach the tool untouched, so the jq/yq owners decide their
  own output; `EntryPoint::version()` returns the release string if they want to include it.
- **VERSION is the single source of truth**, read by the PHAR build, the release preflight, the
  smoke tests and the workflow. The tag is `v<VERSION>`.
- **Extension set.** `packaging/extensions.txt` (`phar`, `mbstring`, `ctype`) united with every `ext-*`
  declared in `composer.json` `require`. Declaring an extension in `composer.json` is therefore
  enough; the CLI owners should add `ext-mbstring` and `ext-ctype` there when they depend on them.
- **Runtime ini.** `memory_limit=-1` is injected at `micro:combine`, so a large document is not
  killed by PHP's default limit; this mirrors jq, which has no such limit.
- **Platform matrix.** Required: linux-x86_64, linux-aarch64 (native runners, spc compiles natively).
  Optional (warning, release continues): macos-aarch64, macos-x86_64. Windows is out of scope for
  0.1.0.
- **Asset names are unversioned** (`phpxq-linux-x86_64`, ...) so `releases/latest/download/` works for
  `install.sh`.
- **Conformance on the packaged artefact.** `PHPXQ_BINARY=<artefact> scripts/conformance-shell.bash`
  runs the upstream shell suites against a built binary instead of `bin/phpxq`. The data-driven PHP
  suites still drive the front controller in-process; running them against a binary is open work for
  when the tools exist (Task 3.1).

## Local verification record

- PHAR: builds, byte-identical across two clean builds, passes `scripts/smoke-test.bash`.
- `install.sh`: installs and checksum-verifies against a local mirror (`PHPXQ_BASE_URL=file://...`),
  refuses a tampered download without installing.
- Release scripts (`release-preflight`, `release-assets`): exercised for the tag-free, tag-present,
  unreachable-remote and missing-asset cases.
- Static binary (linux-x86_64, built locally with spc 2.8.5): about 11.5 MB, fully static, passes
  `scripts/smoke-test.bash --static` with an empty environment, including argv0 dispatch through
  `jq` and `yq` symlinks, and the shell conformance suites run against it through `PHPXQ_BINARY`.
  The other platforms are proven by the CI matrix only.

## Open items

- Task 2.4 Homebrew tap (optional) is not done.
- Task 3.1 data-driven conformance against the binary, and 3.2 clean-container install smoke tests
  beyond the `verify` job, wait for working tools.
