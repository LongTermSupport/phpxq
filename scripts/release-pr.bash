#!/usr/bin/env bash
# Opens or refreshes the release pull request (docs/RELEASING.md), run from a checkout of `main`.
#
# When `## Unreleased` in CHANGELOG.md has entries, this builds the branch chore/release from main with
# one extra commit that releases them (CHANGELOG.md gets the `## X.Y.Z - date` section, VERSION the new
# version) and opens or updates the pull request chore/release into `release`. When there is nothing to
# release it closes a release pull request that is still open.
#
# It does nothing, loudly, when the `release` branch does not exist yet, or when `release` holds a release
# that has not been merged back into main (the back-merge pull request must land first, otherwise the two
# branches would conflict on CHANGELOG.md and VERSION).
#
# Needs: GH_TOKEN (contents, pull requests and actions write), git, gh, php and the dev dependencies.
# Usage: scripts/release-pr.bash
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

release_branch=release
pr_branch=chore/release
: "${GH_TOKEN:?GH_TOKEN must be set for gh}"

cd "$root"
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

open_release_pr() {
    gh pr list --head "$pr_branch" --base "$release_branch" --state open --json number --jq '.[0].number // empty'
}

if ! remote_branch_exists "$release_branch"; then
    echo "::warning title=No release branch::origin has no '$release_branch' branch, so no release pull request can be opened. Create it once (docs/RELEASING.md, 'One-off GitHub configuration')."
    exit 0
fi

git fetch --quiet --tags origin "+refs/heads/$release_branch:refs/remotes/origin/$release_branch"

if ! git merge-base --is-ancestor "origin/$release_branch" HEAD; then
    echo "::notice title=Back-merge pending::'$release_branch' has commits main does not. Merge the back-merge pull request first; the release pull request is refreshed on the next green push to main."
    exit 0
fi

version="$(scripts/release.bash next-version)"

if [[ -z "$version" ]]; then
    number="$(open_release_pr)"
    if [[ -n "$number" ]]; then
        gh pr close "$number" --delete-branch --comment '"## Unreleased" has no entries any more, so there is nothing to release.'
        echo "Closed release pull request #$number: nothing left to release."
    else
        echo "Nothing to release: \"## Unreleased\" has no entries."
    fi
    exit 0
fi

git switch --quiet -C "$pr_branch"
scripts/release.bash prepare >/dev/null
git add CHANGELOG.md VERSION
git commit --quiet -m "Release $version"

body_file="$(mktemp)"
trap 'rm -f "$body_file"' EXIT
{
    echo "Merging this pull request releases **$version**: the release workflow runs the full QA gate, builds the PHAR and the static binaries, tags \`v$version\` and publishes the GitHub Release. Nothing is released until it is merged."
    echo
    echo "Merge it with a **merge commit** (not squash, not rebase). Every green push to \`main\` refreshes this pull request, so it always releases everything recorded so far."
    echo
    scripts/release.bash notes "$version"
} >"$body_file"

pushed=false
if remote_branch_exists "$pr_branch"; then
    git fetch --quiet origin "+refs/heads/$pr_branch:refs/remotes/origin/$pr_branch"
    if git diff --quiet "origin/$pr_branch" HEAD; then
        echo "$pr_branch is already up to date."
    else
        git push --quiet --force-with-lease="$pr_branch:$(git rev-parse "origin/$pr_branch")" origin "$pr_branch"
        pushed=true
    fi
else
    git push --quiet origin "$pr_branch"
    pushed=true
fi

number="$(open_release_pr)"
if [[ -n "$number" ]]; then
    gh pr edit "$number" --title "Release $version" --body-file "$body_file"
    echo "Updated release pull request #$number for $version."
else
    gh pr create --base "$release_branch" --head "$pr_branch" --title "Release $version" --body-file "$body_file"
    pushed=true
    echo "Opened the release pull request for $version."
fi

# A pull request or push made with GITHUB_TOKEN starts no workflow, so the required QA check would never
# report on the new head commit. workflow_dispatch is the event GITHUB_TOKEN may start.
if [[ "$pushed" == true ]]; then
    gh workflow run qa.yml --ref "$pr_branch"
fi
