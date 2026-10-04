# Plan 00007: hot spots per benchmark workload

Supporting document for [PLAN.md](PLAN.md) Task 1.2. For every workload of the benchmark suite
(`tests/Support/Bench/WorkloadCatalogue.php`, Plan 00005) this lists where the time goes, after the
optimisations of [results.md](results.md) and [results-yq.md](results-yq.md) (so this is the profile of the
code as shipped, i.e. what remains).

## Method

- Shares: `scripts/bench/profile.bash <size> <filter> <reps>` (pcntl sampling profiler, one sample per 500
  microseconds of wall time while the process runs; `.yaml` input profiles yq). The share is SELF, the
  innermost frame. Native functions (`json_decode`, `preg_match`, `sort`, `array_multisort`) are reported under
  their own name, so "json_decode" is time inside PHP's C parser, not phpxq code. Raw reports were produced
  with 5 to 8 repetitions in one process (40 for the tiny inputs, 1 for the large inputs). Samples taken under
  heavy host load (load average above 30) still count process time only, so shares are usable; absolute
  numbers are not taken from the profiler.
- CPU ms: `scripts/bench/artefact-compare.bash --set full`, user plus sys, minimum of 5 runs, column
  `checkout` (`php bin/phpxq`, PHP 8.5.11, opcache off for the CLI, no JIT). The host was shared with other
  lanes the whole time (load average 35 to 40), so only the minimum is meaningful.
- The floor for every workload is the bare PHP start: `php -r '1;'` costs about 45 to 56 ms of CPU on this
  host with the default ini (about 20 ms of that is the many default extensions it loads;
  [results-binary.md](results-binary.md)); the static binary has four extensions and starts in about 30 ms.

## jq

| workload         | CPU ms | where the time goes (self share of samples)                                                                                                                                             |
| ---------------- | -----: | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| startup          |     60 | PHP start itself (about 56 of the 60); phpxq's own part is `include` of about 30 class files and the lazy builtin registry (`registerLazy`, `DefSet->size`, `getenv`, `preg_match`)     |
| identity-small   |     62 | encoder `JsonEncoder->plain` 23, `json_decode` 17, `JsonObject->__construct` 5, `convertPlain` 5, `include` 4; the rest is startup                                                      |
| select-small     |     60 | no measurable work above startup (small input is 11 KB)                                                                                                                                 |
| identity-medium  |    133 | `JsonEncoder->plain` 40, `json_decode` 22, `JsonDecoder::convertPlain` 11, `mb_check_encoding` 3.5, `JsonDecoder->fast` 3.3, `quote` 2.5                                                |
| select-medium    |     93 | `json_decode` 41, `convertPlain` 21, `JsonDecoder->fast` 5.6, `include` 4.3, `SelectOp->run` 3.1, `JsonObject->get` 2.9: input decoding is about 70 percent, the filter itself under 10 |
| aggregate-medium |     97 | `json_decode` 37, `convertPlain` 24, `decodeAll` 5, `fast` 5, native `sort` (for `unique`) 4.4                                                                                          |
| group-medium     |    112 | `json_decode` 28, `convertPlain` 16, `array_multisort` 6.7, `fast` 5.1, `Values::compare` 4.4, `nativeOrder` 2.8, `groupBy` 2.6                                                         |
| identity-large   |   1291 | `JsonEncoder->plain` 49, `convertPlain` 20, `fast` 3.8, `json_decode` 3.8, `mb_check_encoding` 3.6, `JsonObject->__construct` 3.5, `strcspn` 3.3                                        |
| select-large     |    662 | `convertPlain` 42, `fast` 11, `json_decode` 11, `JsonObject->__construct` 7.4, `decodeAll` 5, `JsonObject->get` 4.2                                                                     |
| aggregate-large  |    727 | same shape as select-large (decode dominated), plus native `sort` for `unique`                                                                                                          |
| group-large      |   1254 | `Values::compare` 14, `convertPlain` 14, `groupBy` 8.9, `Values::typeName` 7.3, `json_decode` 7.2, `array_multisort` 7.2, `compareLists` 5.6                                            |
| wide-keys        |     76 | `json_decode` 34, native `sort` (of 10k keys) 25, `fast` 11.5, `array_map` 6.6, `include` 6; the 300 KB input is mostly one wide object                                                 |
| deep-walk        |     45 | startup dominated (`include` 10, `registerLazy` 4, `getenv` 3); the native walk leaves no evaluator frame above 3 percent                                                               |

Reading: on every input larger than a few KB the cost is the input decode (`json_decode` plus the
`convertPlain` pass that turns PHP arrays into `JsonObject`/list values: together 50 to 65 percent of
select, aggregate and large workloads) and, for `.`, the encoder (40 to 49 percent). The evaluator is only
visible in group-large (`Values::compare`, `typeName`, `groupBy`: about 30 percent), where the keys are
compared in PHP. jq 1.6 on the same host needs 1926 ms CPU for identity-large and 1851 for group-large
(results.md), so identity, aggregate and select on large input are at or better than reference speed;
startup (60 ms against 40) and small inputs remain the gap.

## yq

| workload         | CPU ms | where the time goes (self share of samples)                                                                                                                       |
| ---------------- | -----: | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| startup          |     58 | PHP start; phpxq part is class loading (`include`)                                                                                                                |
| identity-small   |     66 | `FastBlockParser::parse` 25, `YamlWriter->plainScalarText` 17.5, `preg_match` 12, `emitBlockMapping` 6.7, `YqApplication->dispatch` 6.4                           |
| select-small     |     62 | no measurable work above startup                                                                                                                                  |
| identity-medium  |    338 | `FastBlockParser::parse` 31, `plainScalarText` 19, `preg_match` 13, `emitBlockMapping` 8, `dispatch` 4.7: parse about 48 percent inclusive, emit about 46 percent |
| select-medium    |    303 | `FastBlockParser::parse` 45, `preg_match` 18 (inside the parse and scalar resolution), `NodeOps::isMergeKey` 7, `dispatch` 5, `Traversal::lookup` 2.8             |
| aggregate-medium |    290 | parse 39, `preg_match` 16.5, `isMergeKey` 6, `dispatch` 5.8, `Traversal::lookup` 3.7                                                                              |
| group-medium     |    362 | parse 33, `Node->copyPlain` 17.6 (copying the grouped nodes into the result), `preg_match` 13, `dispatch` 5, `ResultPrinter->print` 4.9                           |
| identity-large   |   6016 | parse 37, `plainScalarText` 16, `preg_match` 13, `emitBlockMapping` 8, `emitNode` 3, `Node->__construct` 2.2                                                      |
| select-large     |   4662 | parse 51.5, `preg_match` 18.5, `isMergeKey` 4.9, `Node->__construct` 3.8, `Traversal::lookup` 2.1, `ScalarResolver::resolve` 1.8                                  |
| aggregate-large  |   4893 | same shape as select-large                                                                                                                                        |
| group-large      |   6418 | same shape as group-medium (parse, `copyPlain`, `preg_match`)                                                                                                     |
| wide-keys        |    164 | parse 35, `preg_match` 14, `CollectionCalls->keys` 11, `ScalarResolver::resolve` 8.4, `resolveMemo` 8.1, `Node->__construct` 7.8                                  |
| deep-walk        |     64 | startup dominated; the walk shows `Evaluator->evaluate` 6.7, `Cross::run` 5.8, `Candidate->__construct` 4.2, `Cands::derive` 4.2                                  |

Reading: the YAML parse (`FastBlockParser::parse` plus the `preg_match` calls it makes) is 50 to 70
percent of every select, aggregate and group workload; emitting is 45 percent of identity. The three
remaining named candidates, none of them taken because each is under 20 percent and the previous round
already measured the first two as noise: `isMergeKey` (5 to 7 percent of select and aggregate, the
Traversal test was already tried and removed, results-yq.md), `YamlWriter->plainScalarText` (16 to 19
percent of identity, its memoisation was tried and removed) and `Node->copyPlain` (17.6 percent of
group). The profiles are flat below these, so further gains need structural change (a different node
representation), not micro-optimisation.
