# Releasing phpxq

A release is a pull request from `main` into a branch named `release`. Merging that pull request runs
`.github/workflows/release.yml`, which builds, verifies, tags and publishes. Nothing is published by
hand and no one tags manually.

The first public version is `0.1.0`.

## Cutting a release

1. On `main`, set the new version in `VERSION` (one line, `MAJOR.MINOR.PATCH`, optionally with a
   pre-release suffix such as `-rc.1`). `VERSION` is the single source of truth: the PHAR, the
   binaries, `phpxq --version`, the git tag and the release title all derive from it.
2. Merge that to `main` through the normal flow and let the QA workflow go green.
3. Open a pull request with base `release` and head `main`. For the very first release the `release`
   branch does not exist yet: create it once from the commit you want to ship
   (`git push origin <sha>:refs/heads/release`) before applying the branch protection below, then
   apply the protection. From then on use pull requests. Pushing the branch the first time also runs
   the release workflow, so do that only when `0.1.0` is ready to ship.
4. Merge the pull request. Use a merge commit; do not squash or rebase (squashing severs ancestry
   between `main` and `release` and makes the next pull request conflict).
5. Watch the `Release` workflow. The run summary lists every stage. On success the tag `vX.Y.Z` and the
   GitHub Release exist, with the artefacts, `SHA256SUMS` and generated notes.

If `VERSION` was not bumped, the workflow stops at preflight with `tag vX.Y.Z already exists`. Bump
`VERSION` on `main` and open a new pull request.

## What the workflow does

| Stage     | What it does                                                                                                                                | If it fails                                           |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| preflight | Only runs on `release`; reads `VERSION`; refuses if the tag exists locally or on the remote                                                 | Nothing is built or published                         |
| qa        | `qa -t allCS`, `qa -t allStatic`, the unit suite, `scripts/conformance.bash all`                                                            | Nothing is published                                  |
| phar      | `scripts/build-phar.bash --check-reproducible` (two clean builds must be byte-identical), smoke test                                        | Nothing is published                                  |
| binary    | `scripts/build-binary.bash` per platform, then `scripts/smoke-test.bash --static` with an empty environment                                 | Linux failure: nothing is published. macOS: see below |
| release   | `scripts/release-assets.bash` (required assets present, `SHA256SUMS`), re-checks the tag, `gh release create` makes the tag and the release | Nothing is published                                  |
| verify    | Downloads the published `install.sh`, installs the published release on x86_64 and aarch64 Linux, checks `phpxq --version`                  | The release exists but is suspect: see "Recovering"   |
| report    | Writes a stage table to the run summary and fails the run if release or verify did not succeed                                              | -                                                     |

Failure modes are loud: every refusal prints a `::error` annotation naming the cause, a failed stage
fails the run, and the `report` job always runs.

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

## One-off GitHub configuration (repository owner)

Do these once, in the repository settings.

1. **Actions permissions.** Settings, Actions, General, Workflow permissions: "Read and write
   permissions". The release job declares `contents: write` itself; the repository setting must not
   cap it lower. Allow GitHub-authored actions and `shivammathur/setup-php`.

2. **Create the `release` branch** (first release only), from the commit to ship, before protecting it.

3. **Protect `release`.** Settings, Branches, add a protection rule for `release`:

   - Require a pull request before merging (at least one approval if you have collaborators).
   - Require status checks to pass: select the QA workflow's `QA gate (read-only qa)` check.
   - Require branches to be up to date before merging.
   - Restrict who can push to matching branches, so only the pull request merge lands there.
   - Do not allow force pushes; do not allow deletions.
   - Leave "require linear history" off: merge commits are the expected shape.

   `vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash --branch release` applies a sensible
   baseline from a terminal with an authenticated `gh`.

4. **Protect tags** (recommended). Settings, Rules, Rulesets: a tag ruleset on `v*` that blocks
   deletion and updates, so a published version can never be moved. The workflow creates tags, so
   leave creation allowed for GitHub Actions.

5. **Branch name policy.** `release` is a pull request base, not a head, so php-qa-ci's
   `branchNamePolicy` is unaffected. Pull requests into `release` come from `main`.

6. **Make the repository public** before the first release if the install script is to work for
   everyone: anonymous `curl` of release assets needs a public repository.

No secrets are needed: the release uses the workflow's own `GITHUB_TOKEN`.

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

Pinned tool versions and their checksums live in `packaging/tools.env`; the extensions compiled into
the static runtime are `packaging/extensions.txt` plus every `ext-*` in `composer.json`. Box is a
pinned, checksum-verified download rather than a Composer dev dependency, because `humbug/box` cannot
be resolved alongside php-qa-ci's current dependency set. Design notes:
`CLAUDE/Plan/00006-static-binary-packaging/packaging-notes.md`.

## Recovering from a bad release

- **Workflow failed before `release`:** nothing was published. Fix on `main`, then open a new pull
  request into `release`. The version does not need to change because no tag was created.
- **Workflow failed in `release` after the tag was created:** check the Releases page. If a release
  exists with missing assets, delete the release and the tag (`gh release delete vX.Y.Z --cleanup-tag`),
  then re-run the workflow from the `release` branch.
- **`verify` failed:** the release is live but its install path is broken. Delete it as above and
  re-run, or ship a fix as the next patch version. Prefer the next patch version once users could have
  downloaded the release.

## Not done yet

- Homebrew tap (Plan 00006 task 2.4, optional).
- Windows builds.
- Build provenance attestation (`actions/attest-build-provenance`) once the repository is public.
- A real functional smoke test: `scripts/smoke-test.bash` skips the jq/yq functional checks while a
  tool still exits 70 ("not implemented") and runs them as soon as it does not.
