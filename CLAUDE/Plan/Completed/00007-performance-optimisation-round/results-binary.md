# Plan 00007: build-level optimisation of the shipped artefacts

Supporting document for [PLAN.md](PLAN.md) Task 2.5. The question: which build or runtime settings of the
PHAR and of the static binary make phpxq measurably faster than the plain checkout, and which are worth
adopting in `scripts/build-phar.bash` / `scripts/build-binary.bash`.

## Method

- Artefacts built in this container (Debian, PHP 8.5.11, 8 cores): `scripts/build-phar.bash` (Box 4.7.0,
  1.67 MB, uncompressed) and `scripts/build-binary.bash` (static-php-cli 2.8.5, micro SAPI, extensions
  ctype, json, mbstring, phar and zlib, about 13 MB).
- Tool: `scripts/bench/artefact-compare.bash [--reps N] [--set small|full] LABEL=COMMAND...`: user plus sys
  CPU milliseconds, **minimum of N runs** (N = 5 to 15), every column of a row measured back to back. The host
  was shared with other lanes throughout (load average 25 to 41), so wall clock is meaningless and
  differences below about 5 percent are treated as noise; a win has to repeat in a second pair of columns.
- "PHAR" columns run `php dist/phpxq.phar` with the container's system PHP (default ini, about 35
  extensions loaded). "binary" columns run the static binary, which has no php.ini and four extensions.
- Smoke tests (`scripts/smoke-test.bash`, PHAR and `--static` binary) and `scripts/conformance.bash all`
  and `PHPXQ_BINARY=dist/phpxq-linux-x86_64 scripts/conformance-shell.bash all` pass after the change.

## Where the shipped artefacts stand (CPU ms, min of 5)

| workload           | checkout | PHAR on system PHP | static binary (-Os) |
| ------------------ | -------: | -----------------: | ------------------: |
| jq startup         |       60 |                 67 |                  33 |
| jq identity-small  |       62 |                 70 |                  34 |
| jq select-medium   |       93 |                104 |                  69 |
| jq group-medium    |      112 |                123 |                  92 |
| jq identity-large  |     1291 |               1289 |                1405 |
| jq group-large     |     1254 |               1175 |                1232 |
| yq startup         |       58 |                 69 |                  32 |
| yq identity-medium |      338 |                366 |                 348 |
| yq group-medium    |      362 |                385 |                 332 |
| yq identity-large  |     6016 |               6047 |                7145 |

Findings: the static binary starts in about 30 ms against 60 to 67 for PHP plus the PHAR, because it
loads four extensions instead of about 35 (`php -n -d extension=phar` measured 39 to 44 ms against 64 for
the same PHAR with the default ini). The PHAR itself costs about 7 to 10 ms over the checkout (opening a
405-file archive and the phar stream wrapper). On the largest inputs the binary is slower than the system
PHP (yq identity-large 7145 against 6047 ms): the binary was built with spc's size-optimised flags (`-Os`)
and links musl; the memory allocator is a plausible further cause (not measured here). The compiler flags
are the lever that fits in the build script.

## Levers measured

| lever                                                                                                      | result                                                                                                                                            | decision                                                                                                                                                         |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C flags of the static runtime, `-Os` (spc default), `-O2`, `-O3`                                           | yq medium and large 7 to 13 percent faster at `-O2`, a further 0 to 5 percent at `-O3`; jq within noise, startup identical; size +170 KB at `-O2` | **adopted `-O2`** (see below); `-O3` not adopted, its extra gain is inside the noise                                                                             |
| PHAR gzip compression (`compressFiles(Phar::GZ)`)                                                          | 455 KB instead of 1.67 MB; CPU the same as uncompressed on every workload (startup 67 against 68)                                                 | rejected: no speed gain, the binary would shrink 1.2 MB at the price of a less simple, less reproducible build                                                   |
| Whitespace and docblocks stripped from the PHAR sources (`php_strip_whitespace`, 1.14 MB)                  | startup 66 to 69 against 67 to 68, medium workloads equal                                                                                         | rejected: no measurable change without opcache                                                                                                                   |
| Autoloader without the `is_file()` guard and without the `..` path in `bin/phpxq` (stripped PHAR, 15 reps) | startup 63 against 66 to 67, medium workloads equal; at the edge of the noise                                                                     | rejected                                                                                                                                                         |
| `classmap-authoritative` / `--optimize-autoloader`                                                         | already on in the PHAR build, but `bin/phpxq` registers its own PSR-4 closure and never loads Composer's loader, so there is nothing to tune      | nothing to do                                                                                                                                                    |
| `opcache.enable_cli=1` on the PHAR                                                                         | startup 90 against 68 ms: compiling and caching on every cold start costs more than it saves                                                      | rejected                                                                                                                                                         |
| `opcache.enable_cli=1` with `opcache.file_cache` and `validate_timestamps=0`                               | startup 71 against 68; small and medium jq 63 against 70 and 108 against 126, yq unchanged                                                        | rejected: needs a writable cache directory and the opcache extension in the static runtime (not compiled in), gain only on some jq workloads, startup not better |
| Preloading                                                                                                 | needs opcache in a long-lived SAPI; a one-shot CLI process cannot use it                                                                          | not applicable                                                                                                                                                   |
| JIT                                                                                                        | needs opcache; the work is short and allocation bound                                                                                             | not applicable (opcache absent in the binary)                                                                                                                    |
| `pcre.jit=0` (PHAR and binary)                                                                             | 15 to 30 percent slower on medium workloads (jq select-medium 91 against 69, group 119 against 92, yq group 437 against 357)                      | rejected; the default (on) stays                                                                                                                                 |
| `zend.assertions=-1` baked into the binary                                                                 | within noise on every workload (`src` has only 4 `assert()` calls)                                                                                | rejected                                                                                                                                                         |
| `zend.enable_gc=0` baked into the binary                                                                   | yq medium 346 against 378 and 337 against 357, jq equal; both applications already control the collector themselves                               | rejected: noise-level, and it would remove the periodic collection jq does on big inputs                                                                         |
| `realpath_cache_size=0`                                                                                    | within noise                                                                                                                                      | rejected                                                                                                                                                         |
| `opcache.validate_timestamps=0`, `opcache.enable_cli` in the binary                                        | no opcache extension in the binary                                                                                                                | not applicable                                                                                                                                                   |
| A single concatenated source file                                                                          | not built; the autoload experiment above bounds the possible gain at about 3 ms of 30 to 60 ms startup                                            | not pursued                                                                                                                                                      |

## Adopted: `-O2` for the static runtime

`scripts/build-binary.bash` exports `SPC_DEFAULT_C_FLAGS="-fPIC -O2"` unless the caller sets it (spc's
default is `-fPIC -Os`). Before and after, CPU ms, minimum of 7 runs, two measurement rounds (columns
back to back, binary built by the script itself):

| workload           | -Os | -O2 | -Os (2nd) | -O2 (2nd) |
| ------------------ | --: | --: | --------: | --------: |
| jq startup         |  34 |  33 |        34 |        33 |
| jq identity-small  |  35 |  34 |        35 |        33 |
| jq select-medium   |  69 |  68 |        71 |        66 |
| jq group-medium    |  94 |  86 |        89 |        87 |
| yq startup         |  28 |  30 |        31 |        30 |
| yq identity-small  |  38 |  35 |        38 |        36 |
| yq identity-medium | 351 | 314 |       355 |       336 |
| yq group-medium    | 352 | 313 |       341 |       303 |

Full set (min of 5, separate run): yq identity-large 7145 to 6372 ms, select-large 5228 to 4531,
aggregate-large 5290 to 4670, group-large 7010 to 6506, yq wide-keys 135 to 123; jq select-large 780 to 705,
group-large 1232 to 1163; jq identity-large 1405 to 1466 (noise range; its measured spread was 1290 to
1470 across columns). `-O3` on the same set: yq identity-large 6153, select-large 4649, group-large 6324,
jq group-large 1108.

Cost: the binary grows from 13,036,401 to 13,208,113 bytes. The build time is unchanged in practice (PHP is
compiled either way).

## Not done

- Investigating why the static binary is slower than the system PHP on large inputs (5 to 18 percent;
  the allocator is untested as the cause).
- Opcache with a file cache in the binary (rejected above).
