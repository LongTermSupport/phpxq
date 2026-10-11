# Releasing phpxq

Releases are cut from the changelog. Nobody picks a version number, edits `VERSION`, or tags by hand:

- Every change a user could notice is recorded under `## Unreleased` in `CHANGELOG.md`. QA refuses a
  pull request that changes a shipped path without an entry.
- The headings of those entries choose the next version (plain SemVer).
- When `main` is green and has unreleased entries, a bot keeps a **release pull request** open
  (`chore/release` into `release`). Merging it is the decision to release.
- The merge runs `.github/workflows/release.yml`: full QA and conformance, PHAR and static binaries,
  smoke tests, then the tag `vX.Y.Z` and the GitHub Release with the changelog as its notes.
- A **back-merge pull request** (`release` into `main`) then brings `VERSION` and `CHANGELOG.md` on `main`
  in line with what was released.

```text
 feature branch --PR--> main                     (QA gate; the changelog lane demands an entry)
                          |
                          | green push to main, `## Unreleased` has entries
                          v
                   release-pr.yml                chore/release = main + "Release X.Y.Z" commit
                          |                      (changelog section written, VERSION bumped)
                          v
              PR: chore/release --> release      a person reviews it and merges it (merge commit)
                          |
                          | push to release
                          v
                     release.yml
        preflight -> qa -> phar -> binaries -> tag vX.Y.Z + GitHub Release -> verify install
                          |
                          v
              PR: release --> main               opened by release.yml, merged (auto-merge if enabled)
                          |
                          v
        main: VERSION = X.Y.Z, `## Unreleased` holds only what came after the release PR was cut
```

## What to do day to day

1. Work on a `feature/`, `bugfix/` or `chore/` branch and open a pull request into `main`.

2. If the change touches `src/`, `bin/`, `scripts/`, `packaging/`, `composer.json`, `box.json` or
   `install.sh`, add a changelog entry. The helper puts it under the right heading:

   ```bash
   vendor/bin/changelog-release add-entry fixed '**`jq` no longer ...** What a user sees.'
   ```

   A change nobody could notice (an internal refactor, a test helper under `scripts/`) takes a commit
   trailer instead, with a reason of at least two words:

   ```bash
   git commit --trailer 'Changelog: none — internal refactor, no behaviour change'
   ```

3. Merge to `main`. When QA is green the release pull request appears or refreshes by itself.

4. When you want to ship, review the release pull request (its body is the release notes) and merge it
   with a **merge commit**. Never squash or rebase: that severs the ancestry between `main` and `release`.

5. Watch the `Release` run. Merge the back-merge pull request it opens (it merges itself when the repository
   allows auto-merge).

## How the version is chosen

`VERSION` holds the newest released version. The release pull request applies the strongest bump among the
`## Unreleased` headings to it:

| Heading                  | Bump while the major is 0 | Bump from 1.0 |
| ------------------------ | ------------------------- | ------------- |
| `### Changed — breaking` | minor                     | major         |
| `### Removed`            | minor                     | major         |
| `### Added`              | minor                     | minor         |
| `### Changed`            | minor                     | minor         |
| `### Deprecated`         | minor                     | minor         |
| `### Fixed`              | patch                     | patch         |
| `### Security`           | patch                     | patch         |

Only those seven headings are allowed, each at most once and each with at least one entry; the
`changelog` lane of `vendor/bin/qa` enforces it. The table is the one php-qa-ci's lane documents
(`vendor/lts/php-qa-ci/docs/tools/changelog.md`), except that php-qa-ci's major is the PHP line, so a
breaking change only ever moves its minor, whereas phpxq follows plain SemVer. A runtime requirement added
or tightened in `composer.json` must be recorded under `Changed — breaking`; the lane checks that too.

The logic is `scripts/Release/` (unit tested in `tests/Unit/Release/`), run through `scripts/release.php`:

| Command                             | Does                                                                           |
| ----------------------------------- | ------------------------------------------------------------------------------ |
| `scripts/release.bash next-version` | prints the version `## Unreleased` releases as; nothing when it has no entries |
| `scripts/release.bash prepare`      | writes the dated changelog section and `VERSION`                               |
| `scripts/release.bash notes X.Y.Z`  | prints the release notes of a released version                                 |
| `scripts/release.bash verify`       | exit 0 releasable, 3 not a release commit, 1 refused                           |
| `scripts/release.bash reconcile`    | merges a released changelog with the entries `main` gained since               |

When `VERSION` has no tag yet (only before the very first release, `0.1.0`), that version is released as it
stands and no bump is applied. Pre-release suffixes (`-rc.1`) are not produced by this flow.

## What each stage does

| Workflow / stage | What it does                                                                                                                                                                 | If it fails                                           |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| `release-pr.yml` | After QA is green on `main`: opens or refreshes `chore/release`, closes it when `## Unreleased` is empty, stands down while a back-merge is pending                          | A stale or missing pull request; nothing is released  |
| preflight        | `VERSION`, the newest changelog section and the tags must agree; tag `vX.Y.Z` must not exist locally or on the remote                                                        | Nothing is built or published                         |
| qa               | the full `CI=true vendor/bin/qa` pipeline (mutation limited to the change since the previous tag), the measurement check, then `scripts/conformance.bash all`                | Nothing is published                                  |
| phar             | `scripts/build-phar.bash --check-reproducible` (two clean builds must be byte-identical), smoke test                                                                         | Nothing is published                                  |
| binary           | `scripts/build-binary.bash` per platform, then `scripts/smoke-test.bash --static` with an empty environment                                                                  | Linux failure: nothing is published. macOS: see below |
| release          | `scripts/release-assets.bash` (required assets, `SHA256SUMS`), re-runs preflight, `gh release create` makes the tag at the merged commit with the changelog section as notes | Nothing is published                                  |
| verify           | Downloads the published `install.sh`, installs the published release on x86_64 and aarch64 Linux, checks `phpxq --version`                                                   | The release exists but is suspect: see "Recovering"   |
| backmerge        | `scripts/release-backmerge.bash` opens `release` into `main` (or `chore/back-merge` when the changelog conflicts) and enables auto-merge                                     | A warning; merge `release` into `main` by hand        |
| report           | Writes a stage table to the run summary and fails the run if release or verify did not succeed                                                                               | -                                                     |

Refusals are loud: every one prints a `::error` annotation naming the cause, and a failed stage fails the run.
A push to `release` whose `## Unreleased` still has entries (the push that creates the branch, or a hand
merge of `main`) is not a release commit: the run skips every stage and says so in its summary.

### Mutation testing in CI

Infection over all of `src/` takes hours, so php-qa-ci's automatic diff mode holds a branch to what it changed
(php-qa-ci's `docs/tools/infection.md`, "What is mutated"): the source files added, modified or renamed since the
merge base, plus the source named after each changed test. A change to `composer.json`, `composer.lock` or
anything under `qaConfig/` makes the run full, because it can change any mutant's outcome. A diff run counts
uncovered mutants as escaped and is held to one floor for both scores (88, `qaConfig/qa.php`). No script of ours
scopes it.

| Run                          | Mutated                                | Set by                                    |
| ---------------------------- | -------------------------------------- | ----------------------------------------- |
| pull request, branch, manual | changed since the merge base with main | automatic (`GITHUB_BASE_REF`, the branch) |
| push to the default branch   | changed by the push                    | `infectionDiffBase` = the commit before   |
| release                      | changed since the previous `v*` tag    | `infectionDiffBase` = that tag            |
| local `vendor/bin/qa`        | changed since the merge base with main | automatic                                 |

`scripts/check-qa-measurements.bash` reads the first `Infection:` line of the pipeline output (CI keeps it in
`PHPXQ_QA_LOG`) and fails the gate when Infection ran in full for any reason but a configuration change or the
default branch: a shallow clone (hence `fetch-depth: 0`), an unknown default branch or a detached HEAD would
otherwise burn hours measuring nothing the change did. It also fails when the lane did not run, when the summary
is older than the coverage report, or when more than 20% of mutants were skipped. A re-baseline of the floors is a
manual `infectionDiffBase=full vendor/bin/qa` (over an hour); no scheduled full run exists. Each mutating job stops
at a `timeout-minutes` below GitHub's 6-hour limit.

### The release pull request

`chore/release` is `main` plus one commit, `Release X.Y.Z`, that moves the entries into
`## X.Y.Z — date` and bumps `VERSION`. It is force-pushed on every refresh, so never commit to it. Every green
push to `main` refreshes it, so it always releases everything recorded so far. Entries merged to `main` after
the pull request was cut stay under `## Unreleased` for the next release.

### The back-merge

When main gained changelog entries while the release pull request was open, merging `release` back would
conflict on `CHANGELOG.md`. `scripts/release-backmerge.bash` resolves that on `chore/back-merge`: the released
changelog, plus every `## Unreleased` entry of `main` the release did not record. Any other conflict is
refused with a message; merge `release` into `main` by hand then. Until the back-merge lands, `release-pr.yml`
stands down (the two branches would conflict), and resumes on the next green push to `main`.

### Assets

| Asset                 | Platform                            | Required |
| --------------------- | ----------------------------------- | -------- |
| `phpxq.phar`          | Any machine with PHP 8.5            | yes      |
| `phpxq-linux-x86_64`  | Linux x86_64, static, no PHP needed | yes      |
| `phpxq-linux-aarch64` | Linux arm64, static, no PHP needed  | yes      |
| `phpxq-macos-aarch64` | macOS Apple silicon, no PHP needed  | no       |
| `phpxq-macos-x86_64`  | macOS Intel, no PHP needed          | no       |
| `install.sh`          | Installer, see the README           | yes      |
| `SHA256SUMS`          | Checksums of every other asset      | yes      |

macOS builds are optional because static-php-cli on macOS runners is the least proven leg. When one
fails the run shows a warning and the release ships without it. To make a platform mandatory, set its
`optional` to `false` in the `binary` matrix and add it to `required` in `scripts/release-assets.bash`.
The asset names are stable and unversioned so `releases/latest/download/<asset>` always works.

## Cutting the first release (0.1.0, done)

`0.1.0` went through the same flow as every later release. These are the one-off steps that set it up, kept
for a fresh fork or a repository that loses its `release` branch; an ordinary release needs none of them.

1. Do the one-off GitHub configuration below.

2. Make sure `main` is green: QA passes on the commit you intend to ship, and locally
   `CI=true vendor/bin/qa` and `scripts/conformance.bash all` exit 0.

3. Create the `release` branch once, from `main`'s tip, and protect it. The push runs the Release workflow,
   which recognises that `## Unreleased` still has entries and skips every stage:

   ```bash
   git fetch origin
   git push origin origin/main:refs/heads/release
   ```

4. Run the `Release PR` workflow (Actions tab, "Run workflow" on `main`), or push anything to `main`. It opens
   the release pull request (`Release 0.1.0` the first time). Review and merge it with a merge commit.

5. Watch the `Release` run, then check the Releases page: `v0.1.0` with `phpxq.phar`,
   `phpxq-linux-x86_64`, `phpxq-linux-aarch64` (plus macOS when they built), `install.sh`, `SHA256SUMS` and the
   notes. Try the installer:
   `curl -fsSL https://github.com/LongTermSupport/phpxq/releases/latest/download/install.sh | sh`.

6. Merge the back-merge pull request.

## One-off GitHub configuration (repository owner)

Do these once, in the repository settings. None of it can be applied or tested from the repository itself.

**Actions**

1. Settings, Actions, General, Workflow permissions: **Read and write permissions**, and tick **Allow
   GitHub Actions to create and approve pull requests**. The workflows open and refresh pull requests with
   the built-in `GITHUB_TOKEN`; no secret and no personal access token is needed.
2. Allow GitHub-authored actions, `actions/*` and `shivammathur/setup-php`.

**The `release` branch**

3. Create it once (see "Cutting the first release"), then protect it: Settings, Branches (or Rules, Rulesets), `release`:
   - Require a pull request before merging (at least one approval if you have collaborators).
   - Require status checks to pass: `QA gate (read-only qa)`. Require branches to be up to date.
   - Restrict who can push, so only the merged pull request lands there. Block force pushes and deletion.
   - Leave "require linear history" off and allow **merge commits**; do not allow squash or rebase merging
     into `release`.
   - Do not require signed commits: the bot's commits are not signed.
4. `vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash --branch release` applies a baseline from a
   terminal with an authenticated `gh`; compare it with the list above.

**The `main` branch**

5. Require pull requests and the status check `QA gate (read-only qa)`. Allow merge commits (the back-merge
   needs one; the other merge methods are fine for feature pull requests, but never squash the release or
   back-merge pull requests).
6. Settings, General, Pull Requests: tick **Allow auto-merge** so the back-merge pull request merges itself
   once its checks pass. Without it the workflow warns and you merge it by hand.

**Why the QA check is dispatched.** A pull request or push made with `GITHUB_TOKEN` starts no workflow, so the
bot's pull requests would never get the required `QA gate (read-only qa)` check. The scripts therefore run
`gh workflow run qa.yml --ref <branch>` for the head branch; a `workflow_dispatch` run reports its check on the
head commit, which satisfies the requirement. If your protection requires checks from a specific app or
event type, switch to a personal access token or a GitHub App token for `release-pr.yml` and
`release-backmerge.bash` instead.

**Tags**

7. Settings, Rules, Rulesets: a tag ruleset on `v*` that blocks deletion and updates (a published version
   must never move). Leave creation allowed: the workflow creates tags with `GITHUB_TOKEN`.

**Repository**

8. Make the repository **public** before the first release if the installer must work for everyone:
   anonymous `curl` of release assets needs a public repository.
9. Branch name policy: php-qa-ci's `branchNamePolicy` allows `feature/`, `bugfix/`, `chore/` and `hotfix/`
   heads. `chore/release` and `chore/back-merge` fit; `release` is only ever a base (and the back-merge head).

## Install paths

See the README. In short: `install.sh` (verifies the SHA-256 before installing anything), or download
an asset by hand and check it with `sha256sum -c SHA256SUMS`.

## Building locally

```bash
scripts/build-phar.bash --check-reproducible     # dist/phpxq.phar
scripts/smoke-test.bash dist/phpxq.phar
scripts/build-binary.bash                        # dist/phpxq-<os>-<arch>, needs a C toolchain
scripts/smoke-test.bash --static dist/phpxq-linux-x86_64
PHPXQ_BINARY=dist/phpxq-linux-x86_64 scripts/conformance-shell.bash all   # shell conformance on the binary
```

What a local build looks like (Debian container, PHP 8.5, 8 cores): the PHAR is about 1.5 MB (uncompressed:
compression saves little and costs startup) and builds in a few seconds, byte-identical across builds;
the static Linux x86_64 binary is about 13 MB, passes `scripts/smoke-test.bash --static`, and
`PHPXQ_BINARY=... scripts/conformance-shell.bash all` passes on it (jq `shtest`, 17 yq acceptance
scripts). The binary build needs `make`, a C/C++ toolchain, `cmake`, `autoconf` and friends and `sudo`;
`spc doctor --auto-fix` (run by the script) installs them with `apt-get` or `dnf` and takes several
minutes on a cold start, so CI caches the spc workspace. The runtime is compiled with `-O2`
(`SPC_DEFAULT_C_FLAGS`; spc's default is `-Os`), which measured faster on yq; the other build levers
that were tried are in `CLAUDE/Plan/Completed/00007-performance-optimisation-round/results-binary.md`.

Pinned tool versions and their checksums live in `packaging/tools.env`; the extensions compiled into
the static runtime are `packaging/extensions.txt` plus every `ext-*` in `composer.json`. Box is a
pinned, checksum-verified download rather than a Composer dev dependency, because `humbug/box` cannot
be resolved alongside php-qa-ci's current dependency set. Design notes:
`CLAUDE/Plan/00006-static-binary-packaging/packaging-notes.md`.

Rehearse the release scripts without GitHub by pointing them at a scratch clone with a local bare `origin`
and a stub `gh`; the pure decisions are covered by `vendor/bin/phpunit --testsuite unit --filter Release`.

## Recovering

- **The release pull request looks wrong:** fix `## Unreleased` on `main` (merge a pull request). The next green
  push rebuilds it. To rebuild now, run the `Release PR` workflow manually.
- **A release is wrong or unwanted:** close the release pull request. Nothing is published until it merges.
- **Workflow failed before `release`:** nothing was published and no tag exists. Fix on `main`; the
  release pull request refreshes. Re-run the failed `Release` run if the cause was transient.
- **Workflow failed in `release` after the tag was created:** check the Releases page. The `v*` tag ruleset
  (step 7) blocks deleting or moving the tag, so the version cannot be published again. If the release exists
  with an asset missing, attach the asset from the run's artefacts (`gh release upload vX.Y.Z <file>`, then
  regenerate and re-upload `SHA256SUMS` with `--clobber`). Otherwise ship the fix as the next patch version.
- **`verify` failed:** the release is live but its install path is broken. Mark it as not the latest
  (`gh release edit vX.Y.Z --latest=false`, or delete the release but not the tag) and ship the fix as the next
  patch version. Removing the tag itself needs a repository admin to bypass the tag ruleset; do that only when
  the tag must not exist at all, never to re-publish the same version.
- **Preflight says `VERSION says X but the newest section of CHANGELOG.md is Y`:** someone edited `VERSION` or a
  version section by hand. Revert it; only the release pull request writes them.
- **The back-merge was not opened or conflicts on a file other than `CHANGELOG.md`/`VERSION`:**
  `git switch main && git merge --no-ff origin/release`, resolve, and open a pull request.
- **`release-pr.yml` warns "No release branch" or "Back-merge pending":** create the branch, or merge the
  back-merge pull request.

## Known limits

- The changelog lane is off on `main` itself (`qaConfig/ChangelogLaneSwitch.php`): there it measures from an
  `85.N.N` tag that phpxq never creates. It runs on every other branch and on pull requests, which is where a
  change gets recorded.
- Pre-release versions (`-rc.1`) are not produced by the flow; `VERSION` must be plain `X.Y.Z`.
- Homebrew tap (Plan 00006 task 2.4, optional), Windows builds, and build provenance attestation
  (`actions/attest-build-provenance`, once the repository is public) are not done.
- The macOS binaries are only ever built on GitHub's macOS runners.
- The workflows assume a public repository. No checkout keeps the token in `.git/config`, so the preflight's
  `git ls-remote` of the tags runs unauthenticated; only the release-PR and back-merge steps authenticate git
  (`gh auth setup-git`). A private repository would need the same there, and the installer could not download
  release assets anonymously either.
- `install.sh` restricts every download and redirect to HTTPS with curl and with GNU wget. BusyBox wget has no
  such option: the download root is still HTTPS, but a redirect from it to plain HTTP is followed. The SHA-256
  check still applies, and `SHA256SUMS` comes from the same release, so it detects corruption, not a swapped
  release.
