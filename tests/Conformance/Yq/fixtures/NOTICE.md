yq conformance fixtures: provenance

cases.json and skipped.json are generated from the documented examples of mikefarah/yq, the pages
under pkg/yqlib/doc/operators/ and pkg/yqlib/doc/usage/.

Source https://github.com/mikefarah/yq
Tag v4.54.1
Commit 504fc38780cc46be8444ea1b72fb55919fc0bfb0
Licence MIT, Copyright (c) 2017 Mike Farah (see LICENSE in this directory)

The fixture data is derived from upstream documentation and kept under the upstream licence. The
copyright notice and permission text are in LICENSE.

Files

cases.json One entry per extractable example: name, source, heading, command (yq subcommand or
null), flags, expression, input (fed on stdin) and expected (stdout).
skipped.json Examples the extractor could not turn into an unambiguous case, each with a reason
and the snippet. Nothing is dropped silently.

Shell acceptance suite

tests/Conformance/Yq/acceptance/ holds upstream files copied byte for byte (marked -text -diff by the
.gitattributes beside them) from the same tag and commit, run by scripts/conformance-shell.bash:

acceptance/\*.sh from acceptance_tests/ (MIT, Copyright (c) 2017 Mike Farah, see LICENSE here)
acceptance/scripts/shunit2 from scripts/shunit2, the shUnit2 2.1.8 test framework the scripts source

shunit2 is a separate work: Copyright 2008-2020 Kate Ward, released under the Apache License 2.0
(http://www.apache.org/licenses/LICENSE-2.0, https://github.com/kward/shunit2), as stated in its own file
header. The upstream yq clone ships no separate copy of the Apache-2.0 text, so only the header
attribution and the licence URL are recorded here; add the full text from the shunit2 project if a
redistribution policy requires it.

The acceptance directory is excluded from php-qa-ci's shellCheck lane in qaConfig/qa.php: third-party code,
not fixed here.

Refresh

From the repository root run scripts/refresh-upstream-fixtures.bash yq. It clones the pinned tag,
refuses a tag that no longer points at the recorded commit, copies the acceptance scripts, shunit2 and
LICENSE, and regenerates cases.json and skipped.json.

The generator is tests/Support/Yq/YqDocExtractor.php, run through scripts/refresh-yq-fixtures.php
(which takes a path to any yq clone); its unit tests are in tests/Unit/Support/Yq/.

To move to a newer yq release, change the tag and commit pins at the top of
scripts/refresh-upstream-fixtures.bash, run it, and update the tag and commit in this file.
