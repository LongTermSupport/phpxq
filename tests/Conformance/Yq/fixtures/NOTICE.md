yq conformance fixtures: provenance

cases.json and skipped.json are generated from the documented examples of mikefarah/yq, the pages
under pkg/yqlib/doc/operators/ and pkg/yqlib/doc/usage/.

  Source   https://github.com/mikefarah/yq
  Tag      v4.54.1
  Commit   504fc38780cc46be8444ea1b72fb55919fc0bfb0
  Licence  MIT, Copyright (c) 2017 Mike Farah (see LICENSE in this directory)

The fixture data is derived from upstream documentation and kept under the upstream licence. The
copyright notice and permission text are in LICENSE.

Files

  cases.json    One entry per extractable example: name, source, heading, command (yq subcommand or
                null), flags, expression, input (fed on stdin) and expected (stdout).
  skipped.json  Examples the extractor could not turn into an unambiguous case, each with a reason
                and the snippet. Nothing is dropped silently.

Refresh

Check out the pinned tag in a yq clone, then from the repository root:

  git -C /path/to/yq checkout v4.54.1
  php scripts/refresh-yq-fixtures.php /path/to/yq

The generator is tests/Support/Yq/YqDocExtractor.php; its unit tests are in tests/Unit/Support/Yq/.
To move to a newer yq release, update the tag and commit in this file together with the regenerated
JSON.
