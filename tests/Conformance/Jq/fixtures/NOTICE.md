Vendored jq conformance fixtures
================================

These files are copied unmodified from the upstream jq test suite and drive JqConformanceTest.

Source:  https://github.com/jqlang/jq
Tag:     jq-1.8.2
Commit:  34f7186b86743a083a589741b6cea95293524108
Files:   tests/*.test (the seven vendored here); tests/modules/ is vendored in ../modules/
Licence: MIT, copyright Stephen Dolan; see COPYING in this directory.

Documentation-derived files
---------------------------

man.test and manonig.test are generated upstream from the jq manual examples. The manual is licensed
under Creative Commons Attribution 3.0 (https://creativecommons.org/licenses/by/3.0/), author
"jq contributors". They are redistributed here without changes.

Refresh procedure
-----------------

1. Shallow-clone the new tag: git clone --depth 1 --branch <tag> https://github.com/jqlang/jq
2. Copy tests/{jq,man,onig,uri,manonig,base64,optional}.test over the files here, byte for byte (the
   BOM and line endings matter), and tests/modules/ over ../modules/.
3. Copy COPYING over the one here and update the tag and commit SHA in this file.
4. Update the expected case counts in tests/Unit/Support/Jq/JqVendoredFixturesTest.php.
