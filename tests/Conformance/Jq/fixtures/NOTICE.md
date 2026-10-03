# Vendored jq conformance fixtures

These files are copied unmodified from the upstream jq test suite and drive JqConformanceTest.

Source: https://github.com/jqlang/jq
Tag: jq-1.8.2
Commit: 34f7186b86743a083a589741b6cea95293524108
Files: tests/\*.test (the seven vendored here); tests/modules/ is vendored in ../modules/
Licence: MIT, copyright Stephen Dolan; see COPYING in this directory.

## Shell suite files

tests/Conformance/Jq/shell/ holds the upstream shell test driver, copied unmodified from the same tag and
commit (same MIT licence, COPYING in this directory) and run by scripts/conformance-shell.bash:

- shell/tests/{shtest,setup,jq-f-test.sh,no-main-program.jq,yes-main-program.jq,utf8test} and
  shell/tests/torture/ come from upstream tests/. The layout matters: setup derives JQBASEDIR from the
  script directory's parent.
- shell/tests/modules is a relative symlink to ../../modules (the vendored tests/Conformance/Jq/modules);
  it is not a second copy.
- shell/.gitattributes marks everything there -text -diff so git never rewrites the bytes.
- The directory is excluded from php-qa-ci's shellCheck lane in qaConfig/qa.php: third-party code, not fixed here.

## Documentation-derived files

man.test and manonig.test are generated upstream from the jq manual examples. The manual is licensed
under Creative Commons Attribution 3.0 (https://creativecommons.org/licenses/by/3.0/), author
"jq contributors". They are redistributed here without changes.

## Refresh procedure

Run scripts/refresh-upstream-fixtures.bash jq. It clones the pinned tag, refuses a tag that no longer
points at the recorded commit, and copies every vendored file byte for byte (the BOM and line endings
matter), keeping the executable bits and the modules symlink.

To move to a newer jq release:

1. Change the tag and commit pins at the top of scripts/refresh-upstream-fixtures.bash.
2. Run the script, and update the tag and commit SHA in this file.
3. Update the expected case counts in tests/Unit/Support/Jq/JqVendoredFixturesTest.php.
