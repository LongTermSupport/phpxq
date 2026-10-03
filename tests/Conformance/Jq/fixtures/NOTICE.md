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

1. Shallow-clone the new tag: git clone --depth 1 --branch <tag> https://github.com/jqlang/jq
2. Copy tests/{jq,man,onig,uri,manonig,base64,optional}.test over the files here, byte for byte (the
   BOM and line endings matter), and tests/modules/ over ../modules/.
3. Copy COPYING over the one here and update the tag and commit SHA in this file.
4. Copy tests/{shtest,setup,jq-f-test.sh,no-main-program.jq,yes-main-program.jq,utf8test,torture/} over
   ../shell/tests/, keeping the executable bits; the modules symlink stays.
5. Update the expected case counts in tests/Unit/Support/Jq/JqVendoredFixturesTest.php.
