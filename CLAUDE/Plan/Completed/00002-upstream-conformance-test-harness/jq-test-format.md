# Research: jqlang/jq test suites for phpxq

Clone: /workspace/untracked/scratch/upstream/jq (shallow, tag jq-1.8.2).

## 1. Pin candidate

- Latest stable: `jq-1.8.2` (published 2026-06-20; previous 1.8.1, 1.8.0, 1.7.1, 1.7).
- Commit SHA: `34f7186b86743a083a589741b6cea95293524108` (tag is lightweight, resolves directly to this commit).
- Note: `jq-1.8.2rc1` = 5f2a14dd1b03a8b43015058ed006dd4ab24fb58f (ignore).

## 2. tests/ contents (jq-1.8.2)

`.test` data files (processed by `jq --run-tests FILE`, implemented in src/jq_test.c):

| file          | lines | bytes | test cases | of which %%FAIL |
| ------------- | ----- | ----- | ---------- | --------------- |
| jq.test       | 2635  | 52230 | 550        | 19              |
| man.test      | 994   | 13647 | 231        | 0               |
| onig.test     | 211   | 4931  | 47         | 0               |
| uri.test      | 92    | 1599  | 20         | 0               |
| manonig.test  | 89    | 1718  | 19         | 0               |
| base64.test   | 47    | 764   | 10         | 0               |
| optional.test | 12    | 263   | 2          | 0               |
| total         | 4080  | 75152 | 879        | 19              |

(case counts via awk: a case = first non-blank, non-comment, non-`%%FAIL` line of a block; the 19 %%FAIL cases are included in the 550.)

Drivers (each is `jq -L tests/modules --run-tests tests/X.test` via tests/setup): `jqtest`, `onigtest`, `optionaltest`, `base64test`, `manonigtest`, `mantest`, `uritest`, plus `utf8test`.

Other files:

- `shtest` 936 lines / 29372 bytes: CLI-behaviour shell script (about 60 sections). Not data driven; checks exit codes, `--seq`, `--stream`, `--arg/--argjson/--slurpfile/--rawfile/--args/--jsonargs`, `--indent`, `-C` colours / `JQ_COLORS` / `NO_COLOR`, `~/.jq` (HOME), `--raw-output0`, CRLF, `-f file`, syntax error messages on stderr, `debug`/`stderr`, CVE regressions (NUL truncation, circular imports, ARG_NEWCLOSURE overflow with 4097 params), torture test with truncated reads of torture/input0.json via dd.
- `jq-f-test.sh` 4 lines: a `#!/bin/sh` + `exec jq -nef "$0"` script that is itself a jq program (`true`); tests `-f` with shebang line comment continuation.
- `no-main-program.jq`, `yes-main-program.jq`: fixtures for -f / main program detection.
- `modules/` (1738 bytes): a.jq, b/b.jq, c/c.jq, c/d.jq, data.json, shadow1/2.jq, cycle_a/b/self.jq, syntaxerror/syntaxerror.jq, test_bind_order\*.jq, home1/, home2/, lib/jq/e/..., lib/jq/f.jq.
- `torture/input0.json` (173 bytes), `base64.test`.
- C fuzz harnesses (jq_fuzz\_\*.c/cpp), `local.supp`, `onig.supp`: irrelevant (valgrind/fuzz).
- Whole tests/ dir is 123665 bytes.

## 3. .test file format (precise; derived from src/jq_test.c run_jq_tests)

Line oriented, read with fgets (4096-byte limit per line; each program and each input/expected value must be ONE line).

Definitions:

- `skipline(line)`: strip leading spaces/tabs; the line is "skip" if the rest starts with `#`, or is `\n`, or is empty. Used both as the between-block separator and as the comment filter. (Comments are only recognised at line start, with optional indentation; there are no trailing comments.)
- `%%FAIL` marker: line must be exactly `%%FAIL\n` or `%%FAIL IGNORE MSG\n` (no leading whitespace). Checked after the skipline test, at a block boundary.

Grammar:

```
file     := (skipline | block)*
block    := [failmarker] program-line  (input-line expected-line* | errmsg-line*)  terminator
terminator := a skipline (blank line or # comment) or EOF
```

Normal test (3+ lines):

1. program line: the jq filter, whole line, trailing `\n` stripped. Inline whitespace preserved. May start with spaces? (leading ws is not stripped, only the skipline test uses it).
2. input line: one JSON text, parsed with jv_parse (a single value; line 49 of jq.test starts with a U+FEFF BOM that must be tolerated).
3. zero or more expected-output lines: each one JSON value, compared against successive outputs with jq equality (jv_equal, so object key order irrelevant, numbers compared by value; 1 == 1.0). Expected line `1.000` etc. all parse as JSON. The first skipline ends the block. Number of expected lines must equal number of outputs exactly (extra output = "Superfluous result" failure, too few = "Insufficient results"). A test with zero expected lines means the program must emit nothing.

- A test that raises a runtime error that is not caught inside the program is not specially handled: the case is just a program whose output sequence ends (jq_next returns invalid; an uncaught error message is in the invalid jv), so failing runtime errors do not appear as %%FAIL.

`%%FAIL` test (compile-time failure):

1. `%%FAIL` or `%%FAIL IGNORE MSG` line.
2. program line (must FAIL TO COMPILE; if it compiles, test fails).
3. For plain `%%FAIL`: following lines up to the next skipline are the expected jq stderr error message, concatenated (e.g. `jq: error: ... at <top-level>, line 1, column 3:` then 2 caret-context lines indented 4 spaces). Each expected line is compared with strncmp as a prefix of the actual message (error messages are collected only from callback lines beginning `jq: error`), with `\n` consumed between; leftover unmatched actual message = "extra message" failure. With `%%FAIL IGNORE MSG` the lines after the program are read and skipped, message not compared. No input/expected-value lines exist for FAIL cases.

- 18 of the 19 FAIL cases in jq.test check the message; one is IGNORE MSG (`import "syntaxerror" as e; .`, line 1981).
- Messages end with `<top-level>, line 1, column N:` and a source-pointer snippet using `^^^`; reproducing the exact text is only feasible if phpxq replicates jq's bison error text (e.g. `syntax error, unexpected ']', expecting BINDING or '[' or '{'`). Recommendation: for PHPUnit, treat FAIL as "exit non-zero from compile" and optionally compare only the first message line, with a separate expectations flag for exact-message cases.

Other notes:

- Test-case line numbers (`line number N`) are 1-based file lines; use `file:line` of the program line as PHPUnit data-set key (e.g. `jq.test:39`).
- Output ordering of keys in expected lines is irrelevant (jv_equal); but via the CLI `-c` the actual output is compared after JSON-decode, so decode both sides and compare semantically (careful with big numbers: use string-preserving decode, see section 4).
- With USE_DECNUM builds the harness also checks that each expected value survives dump+reparse equality; nothing needed for us.
- Programs may reference `$__loc__`, `input`, `inputs`, `debug` (see below).

## 4. What pure PHP will struggle with; mapping to CLI

Mapping for plain tests: `printf '%s' "$input" | phpxq -c "$program"` (input on stdin, one JSON value; program as argv[1] -- beware programs starting with `-`, e.g. `-1`, use `--` or `-c -- program`). Outputs: one compact JSON value per line; split on newlines, json_decode (or decode with a big-int-safe decoder), compare to each expected-line decoded the same way. Exit code 0 expected for ordinary cases; the uncaught-runtime-error cases cannot be distinguished (not in the suite as FAIL).
For %%FAIL cases: invoke `phpxq -n -c "$program"` (or with `null` on stdin), expect non-zero exit (jq exit 3 for compile errors) and, for message cases, stderr starts with the expected `jq: error: ...` text. Using `-n` avoids needing input.
Caveat on running 550+879 PHP subprocesses: use in-process invocation of the application kernel for speed if available, else per-case process (~879 spawns is ok).

Hard/ill-fitting areas in jq.test (550 cases):

- Module system: ~25 lines reference import/include/modulemeta (lines 1900-1993). Need `-L tests/modules` (the drivers pass `-L $mods`). Cases that use modules: lines ~1900-1993, `import "a" as foo; ...`, `import "data" as $e` (data.json), `modulemeta` with input `"c"` (needs `-L`), `get_search_list`. Map: pass `-L <vendored-or-fetched>/tests/modules`. `lib/jq/e/e.jq` is found via search path `$ORIGIN/../lib/jq` rules (the "home1/home2/lib" dirs are used by shtest, not jq.test).
- Bignum / decnum: ~9 uses of `have_decnum` (lines 2014, 2196-2275) plus man.test line "12345678909876543212345" and `0.12345678901234567890123456789` (jq.test preserves literals; PHP floats lose them). The tests are written as `. == if have_decnum then A else B end`, so phpxq must define `have_decnum` (false is fine, then results use the double-precision branch: 13911860366432392, 1.7976931348623157e+308, etc.) and `have_literal_numbers` (true in jq 1.7+ builds is normal; we can return true/false consistently). Choose `have_decnum=false` for the first milestone. But PLAIN data tests (not the `if have_decnum` ones) containing very large literals still pass if the output goes through float formatting identically (jq prints 17 sig digits, shortest repr, e.g. `1e1000` -> 1.7976931348623157e+308, `3.0` -> 3.0 printed as 3.0 in 1.7+ for literals unchanged!). jq 1.7+ canonicalises/preserves number literals: unchanged literals (like `100000000000000000000`, `1.000`) print as the original text. Expect many number-formatting divergences in PHP; the expected side is compared numerically so only equality semantics matter, but strings via tojson/tostring show text.
- Time functions: strptime, strftime, strflocaltime, mktime, gmtime, localtime (lines 1850-1895, 2548): depend on libc strptime/strftime (timezone, `%Z`, `%z`). PHP has strftime deprecated/removed in 9; implement natively or use DateTime. Some tests set TZ via... not in .test files; `localtime` outputs depend on process TZ (tests run with LC_ALL=C but TZ unset = UTC in CI).
- Unicode: BOM input (line 49), invalid UTF-8 replacement chars (U+FFFD), surrogate pairs, `@uri`, `ltrimstr`, `implode` of invalid codepoints, `ascii`. PHP strings are bytes; need care: `length`, `.[2:4]` slices, `indices`, `explode` must be by codepoint.
- `debug`/`input`/`$__loc__`: `input` and `inputs` (9 cases) need multi-value stdin; the harness feeds only ONE input line, so `inputs` yields nothing and `input` errors "No more inputs" - same as upstream. `debug` writes to stderr (case at line 2335: just must not crash; stdout compare unaffected). `$__loc__` gives `{"file":"<top-level>","line":1}`; `$__prog_args` not used.
- Deep recursion: lines 2558-2585 build 9999-10001 deep nested arrays, `tojson|fromjson` depth limits ("Exceeds depth limit for parsing" at 10000), `getpath([range(10000)|0])`. PHP json_decode default depth 512; needs own limits and no stack overflow (PHP has no hard stack limit but memory; recursion in evaluator may blow up). These are the most likely crashers.
- Heavy loops: `[range(1e6)]`-style cases, `last(range(365*67)|...)` strptime loop; `limit`, `until`, `repeat` large; `ltrimstr`, `splits`. Set per-case timeout.
- NaN handling (15 mentions): `nan < nan`, `[nan]|sort`, `nan|tojson` = `null`, `[nan] == [nan]` false; expected JSON lines `null` for NaN output. PHP NAN semantics differ from jq ordering (nan sorts below numbers, `nan == nan` false, `nan < nan` true). Implement explicitly.
- Sorting/ordering of objects (keys sorted unicode-codepoint order in jq, which for UTF-8 equals byte order — fine with strcmp, not locale collation), `unique`, `group_by`, `tojson` key order (insertion order preserved in jq? jq objects iterate sorted by key for output! jq prints keys in insertion order for `.` but `keys` sorted; `tojson` of object keeps insertion order). Expected data compared as decoded JSON so mostly irrelevant, except `tojson`/`@json`/`tostring` string outputs.
- Regex (onig.test 47, manonig.test 19, plus man.test cases for test/match/capture/sub/gsub/scan/splits): need Oniguruma semantics. PHP preg (PCRE) is close but differs: named captures syntax `(?<n>..)`, flags g,i,x,s,n,l,p,b, `n` = ignore empty matches, Oniguruma-specific `\h` (hex digit in Onig vs horizontal ws in PCRE), `\p{...}`, `\X`, `(?i)` quirks, UTF-8 mode: use /u. Offsets are in codepoints (jq), not bytes: must convert. Expected-output lines include `{"offset":..,"length":..,"string":..,"captures":[{"offset":-1,"string":null,"length":0,"name":null}]}` structure - empty-capture handling must match. Decision: keep onig.test + manonig.test in the PHPUnit set but tag with a PHPUnit group `regex` so they can be isolated.
- `@sh`, `@base32d` (spelled `@base32d`), `@base64d` (base64.test: invalid input returns error string vs garbage), `@uri` (uri.test), `@html`, `@csv`, `@tsv`, `@text`: straightforward but byte vs codepoint care.
- `getpath/1`, `paths`, `pick`, `del`, `to_entries`, `walk`, `limit(-1; error)` (jq 1.8 raises "Invalid limit"?), `ltrimstr` non-string errors, `trim/ltrim/rtrim`, `abs`, `toarray`, `have_literal_numbers`, `trimstr`: 1.8-specific features; test file is for 1.8.2 so features like `ltrimstr`, `trimstr/1`, `toarray`, `pick`, `debug(msg)`, `scan($re; $flags)`, `splits`, `getpath`, `limit`, `skip/2`, `have_decnum`, `$__prog_args`, `ltrimstr`, `@base32d`, `ascii`, `tojson` number canonicalisation all exist in this version. The test count is not stripped for version; so phpxq must target jq 1.8.2 semantics.
- Not testable via stdin only: `$ENV`/`env`, `input_filename`, `input_line_number`, `get_search_list`, `halt`, `halt_error`, `stderr`, `$__prog`: only appear in shtest (not in .test files; grep shows 0 hits for `$ENV`, `env`, `input_filename`, `halt_error`, `splits(` etc. in jq.test).
- Line length: some lines exceed 4096? Not in upstream suite (fgets truncation would break them upstream too).

shtest / jq-f-test.sh mapping (not data driven): each is a bash script with ~60 sections. To port, one PHPUnit method per section would need hand translation: arg handling (`--arg`, `--argjson`, `--slurpfile`, `--rawfile`, `--args`, `--jsonargs`, `--`), `-e` exit status (1 when last output false/null, 4 when no output), `--seq`, `--stream`, `--indent n`, `-S`, `-r`, `-j`, `-a`, `--raw-output0`, `-C` colours and `JQ_COLORS`/`NO_COLOR` env, `-f file` / shebang, `~/.jq` via HOME, exit code 2 usage, 3 compile error, 5 runtime error, stderr messages. These depend on exact CLI flags and messages; recommend a separate later phase (port ~40 high-value sections by hand: args, exit codes, --stream, --seq, -e, -f, -n, -R, -s, --tab, --indent, -S) and skip valgrind/CVE-heap/NUL sections.

## 5. Licence

- jq COPYING: MIT licence, "jq is copyright (C) 2012 Stephen Dolan" (note: the file also names the other contributors/AUTHORS indirectly; copyright line is only Stephen Dolan).
- COPYING also states: documentation (everything under `docs/`) is Creative Commons CC BY 3.0 (https://creativecommons.org/licenses/by/3.0/); the documentation website bundles Twitter Bootstrap (Apache 2.0, in docs/).
- Test files under tests/ are NOT under docs/, so they are covered by the MIT licence: vendoring jq.test, onig.test, uri.test, base64.test, optional.test and the modules/ fixtures is permitted provided the copyright notice and permission notice are included (ship a copy of COPYING, or a `LICENSE-jq` / `NOTICE` file next to the vendored directory).
- Caveat: `man.test` and `manonig.test` are GENERATED from the manual (docs/content/manual/manual.yml example blocks, built by `make tests/man.test`) so their content derives from the CC BY 3.0 documentation. The example data are tiny, but to be conservative, attribute as CC BY 3.0 (source, author "jq contributors", licence link, note that no changes/changes noted) in the same NOTICE file, or exclude them and fetch on demand. MIT and CC BY 3.0 attribution both are satisfied by a single NOTICE with: upstream URL, tag, commit SHA, MIT text, CC BY 3.0 pointer.
- Our own licence: check the repo licence for compatibility; both MIT and CC BY allow redistribution inside a repo with other licences (the vendored dir remains under its licence).

## 6. Recommendation: vendor vs fetch

- Total size of the whole tests/ dir: 123,665 bytes (123 KB); the 7 .test files: 75,152 bytes (73 KB); modules/ 1,738 bytes; torture 173 bytes.
- Recommend VENDOR (commit) the data files at the pinned tag into e.g. `tests/Fixtures/jq/` (or `tests/upstream/jq-1.8.2/`): the 7 `*.test`, `modules/`, `torture/input0.json`, `no-main-program.jq`, `yes-main-program.jq`, `shtest` (reference only), plus `COPYING` and a `NOTICE`/`UPSTREAM.md` recording repo, tag `jq-1.8.2`, commit `34f7186b86743a083a589741b6cea95293524108`, retrieval method, and the licence note (CC BY for man.test/manonig.test). Reasons: tiny (about 120 KB), hermetic offline CI, reproducible/reviewable diffs when bumping the tag, php-qa-ci runs without network, and a red-test inventory (data provider keys `file:line`) that is stable. Keep jq's files byte-for-byte unmodified (BOM, line endings matter) and add `.gitattributes` with `tests/Fixtures/jq/** -text` (or `binary`/`-diff`) so line-ending normalisation and the formatters (markdown/php-cs, whitespace hooks, editorconfig) never touch them; also ensure any whitespace/trailing-newline linters exclude the directory.
- Provide a small refresh script (`bin/` script) that does a shallow clone of a given tag, copies the files, and rewrites the SHA in NOTICE, so updating is a mechanical step; do NOT fetch at test time.
- Alternative (fetch-on-demand into untracked/ or var/) is only attractive if the repo must stay tiny; it adds network flakiness, and a commit-pinned raw URL fetch would need a checksum check.

## Parser sketch (for the implementer)

```
state: lines[], i
loop: skip lines where trim-left(line) starts with '#' or is empty
  if line === '%%FAIL' or '%%FAIL IGNORE MSG': fail=true, checkMsg = (line==='%%FAIL'); next line = program
  program = line (rtrim "\n" only; keep "\r" if any)
  if fail: msg lines = following lines until skipline/EOF
  else: input = next line (if EOF => malformed); expected = following lines until skipline/EOF
  record {file, line (program line number), program, input, expected[], fail, checkMsg, msgLines[]}
```

Edge: a program line that is itself a comment-looking `#...` cannot exist; an input line beginning with whitespace+`#` would be skipped upstream (does not occur). A case at EOF with no trailing blank line is valid. After a normal test's expected lines, upstream's `fail:` path consumes lines until a skipline to resync - mirror this.
