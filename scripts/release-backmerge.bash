#!/usr/bin/env bash
# Opens the back-merge pull request `release` into `main` (docs/RELEASING.md), so VERSION and CHANGELOG.md
# on main converge with what was released. Run after a release was published.
#
# - Nothing to do when main already contains `release`, or when a back-merge pull request is open.
# - When `release` merges into main cleanly, the pull request is `release` itself.
# - When it would conflict (main gained changelog entries since the release pull request was prepared),
#   the merge is made on the branch chore/back-merge: CHANGELOG.md is reconciled (the released changelog
#   plus the entries main gained since, none recorded twice) and VERSION taken from the release. Any
#   other conflicting file is refused with instructions for a manual merge.
#
# Needs: GH_TOKEN (contents, pull requests and actions write), git, gh, php and the dev dependencies.
# Usage: scripts/release-backmerge.bash
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

release_branch=release
main_branch=main
merge_branch=chore/back-merge
: "${GH_TOKEN:?GH_TOKEN must be set for gh}"

cd "$root"
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

git fetch --quiet origin "+refs/heads/$main_branch:refs/remotes/origin/$main_branch" "+refs/heads/$release_branch:refs/remotes/origin/$release_branch"

if git merge-base --is-ancestor "origin/$release_branch" "origin/$main_branch"; then
    echo "main already contains $release_branch: nothing to merge back."
    exit 0
fi

version="$(git show "origin/$release_branch:VERSION")"
version="${version//[$'\r\n ']/}"

direct="$(gh pr list --head "$release_branch" --base "$main_branch" --state open --json number --jq '.[0].number // empty')"
if [[ -n "$direct" ]]; then
    echo "Back-merge pull request #$direct ($release_branch into $main_branch) is already open."
    exit 0
fi

# An open back-merge pull request from $merge_branch is refreshed rather than joined by a second one from
# $release_branch, even when the merge is clean by now.
pending="$(gh pr list --head "$merge_branch" --base "$main_branch" --state open --json number --jq '.[0].number // empty')"

merge_status=0
git merge-tree --write-tree "origin/$main_branch" "origin/$release_branch" >/dev/null || merge_status=$?
case "$merge_status" in
    0) head_branch="$release_branch" ;;
    1) head_branch="$merge_branch" ;;
    *) die "git merge-tree failed (exit $merge_status)" ;;
esac
if [[ -n "$pending" ]]; then
    head_branch="$merge_branch"
fi

if [[ "$head_branch" == "$merge_branch" ]]; then
    git switch --quiet -C "$merge_branch" "origin/$main_branch"
    if ! git merge --no-ff --no-commit --quiet "origin/$release_branch"; then
        scratch="$(mktemp -d)"
        trap 'rm -rf "$scratch"' EXIT
        while IFS= read -r conflicted; do
            case "$conflicted" in
                VERSION)
                    git checkout --theirs -- VERSION
                    git add VERSION
                    ;;
                CHANGELOG.md)
                    git show "origin/$release_branch:CHANGELOG.md" >"$scratch/released.md"
                    git show "origin/$main_branch:CHANGELOG.md" >"$scratch/main.md"
                    scripts/release.bash reconcile "$scratch/released.md" "$scratch/main.md" >"$scratch/merged.md"
                    cp "$scratch/merged.md" CHANGELOG.md
                    git add CHANGELOG.md
                    ;;
                *)
                    echo "::error title=Back-merge needs a human::$conflicted conflicts between $release_branch and $main_branch and is not a file this script reconciles. Merge $release_branch into $main_branch by hand (docs/RELEASING.md)."
                    die "unreconcilable conflict in $conflicted"
                    ;;
            esac
        done < <(git diff --name-only --diff-filter=U)
    fi
    scripts/release.bash next-version >/dev/null
    git commit --quiet -m "Merge $release_branch (v$version) back into $main_branch"
    if remote_branch_exists "$merge_branch"; then
        git fetch --quiet origin "+refs/heads/$merge_branch:refs/remotes/origin/$merge_branch"
        git push --quiet --force-with-lease="$merge_branch:$(git rev-parse "origin/$merge_branch")" origin "$merge_branch"
    else
        git push --quiet origin "$merge_branch"
    fi
fi

existing="$(gh pr list --head "$head_branch" --base "$main_branch" --state open --json number --jq '.[0].number // empty')"
if [[ -n "$existing" ]]; then
    number="$existing"
    echo "Updated back-merge pull request #$number."
else
    body_file="$(mktemp)"
    {
        echo "Brings VERSION and CHANGELOG.md on \`$main_branch\` in line with the published release \`v$version\`, so the next release pull request starts from the released state."
        echo
        echo "Merge it with a **merge commit** (not squash, not rebase). Until it is merged, no new release pull request is opened."
    } >"$body_file"
    number="$(gh pr create --base "$main_branch" --head "$head_branch" --title "Merge release v$version back into $main_branch" --body-file "$body_file")"
    rm -f "$body_file"
    echo "Opened back-merge pull request: $number"
fi

# A pull request made with GITHUB_TOKEN starts no workflow, so dispatch the QA workflow on its head.
gh workflow run qa.yml --ref "$head_branch"

# Merge it as soon as the required checks pass, where the repository allows auto-merge; otherwise a person merges it.
auto_status=0
auto_output="$(gh pr merge "$number" --auto --merge 2>&1)" || auto_status=$?
if [[ "$auto_status" -ne 0 ]]; then
    echo "::warning title=Back-merge needs merging::auto-merge could not be enabled on $number ($auto_output). Merge it by hand with a merge commit."
fi
