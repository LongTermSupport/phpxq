#!/usr/bin/env bash
# The release decisions of the flow (docs/RELEASING.md), run with this repository's tags.
#
# A thin wrapper over scripts/release.php: it writes `git tag --list` to a temporary file and passes it
# to the subcommands that need to know which versions were released. Dev dependencies must be installed
# (the changelog parser comes from php-qa-ci).
#
# Usage: scripts/release.bash <next-version|prepare|notes|verify|reconcile> [arguments]
# Run `php scripts/release.php` without arguments for what each subcommand does and returns.
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

[[ -f "$root/vendor/autoload.php" ]] || die "vendor/autoload.php is missing: run composer install first"

tags_file="$(mktemp)"
trap 'rm -f "$tags_file"' EXIT
git -C "$root" tag --list >"$tags_file"

command_name="${1:-}"
case "$command_name" in
    next-version | prepare | verify) set -- "$@" "--tags-file=$tags_file" ;;
    *) ;;
esac

status=0
(cd "$root" && php scripts/release.php "$@") || status=$?
exit "$status"
