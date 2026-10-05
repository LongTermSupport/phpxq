# Plan 00004 architecture: pure-PHP yq

Supporting document for [Plan 00004](PLAN.md), Task 1.2. It fixes the interfaces that six workers
implement in parallel. Where this document and the skeleton under `src/Yaml` and `src/Yq` disagree, the
skeleton's signatures win and this document is corrected.

## 1. Reference and ground truth

The reference is mikefarah/yq at the tag pinned in `tests/Conformance/Yq/fixtures/NOTICE.md` (v4.54.1).
Ground truth for behaviour is, in order:

1. the vendored documented examples (`tests/Conformance/Yq/fixtures/cases.json`, 574 cases from
   `pkg/yqlib/doc/operators/` and `doc/usage/`), run by `YqConformanceSuite` as
   `phpxq yq [command] [flags] [expression]` with the example input on stdin, expecting exit 0 and
   byte-exact stdout;
2. the shell acceptance scripts in `tests/Conformance/Yq/acceptance/` (run by
   `scripts/conformance-shell.bash`), which exercise the real CLI surface: file arguments, `-i`,
   `-P`, `--front-matter`, `-s`/split, `--nul-output`, completion, bad arguments;
3. the reference source, when a worker needs a detail the docs omit (clone the pinned tag; do not copy
   code, reimplement from behaviour).

Nothing yq does not do is built. A case that cannot pass is listed in
`tests/Conformance/Yq/known-gaps.txt` with a justification, never silently skipped.

## 2. YAML scope

The reference parses YAML 1.2 through go-yaml v3 with yq's own comment handling. We support the same
subset and fail on the same malformed input.

Supported:

- Block mappings and sequences, flow mappings and sequences (nested, multi-line), compact nested
  sequences (`- - a`), explicit keys (`? key`), empty values, null (`~`, `null`, empty), scalar keys of
  any kind and complex (collection) keys.
- Scalars: plain (multi-line folding), single-quoted (`''` escape), double-quoted (all YAML escapes,
  `\u`, `\x`, `\U`, line folding), literal `|` and folded `>` block scalars with chomping (`-` `+`) and
  explicit indentation indicators.
- Core schema tag resolution for plain scalars (`Yaml\Schema\CoreSchema`): null, bool (`true`/`false`
  and case variants only; `yes`/`no`/`on`/`off` are strings), int (decimal, `0o` octal, `0x` hex),
  float (including `.inf`, `.nan`, exponent), str. Explicit tags `!!str`, `!!int`, `!!float`, `!!bool`,
  `!!null`, `!!map`, `!!seq`, `!!binary`, `!!timestamp`, and custom `!tag` are kept verbatim.
- Anchors (`&a`), aliases (`*a`), merge keys (`<<: *a` and `<<: [*a, *b]`); aliases are shared node
  references, merge keys stay in the tree and are resolved by the evaluator (`explode`, and on
  traversal as yq does).
- Multi-document streams (`---`, `...`), `%YAML`/`%TAG` directives (kept as text and re-emitted),
  leading separators, an input holding only comments.
- Comments: head comments (above a node), line comments (same line), foot comments (below a node,
  e.g. trailing comment at the end of a mapping or document), all preserved and re-emitted at their
  original positions.
- Key order, scalar styles and flow/block collection styles preserved on round trip.
- UTF-8 input, UTF-8 BOM stripped, CRLF input accepted (output uses LF).

Not supported (as in the reference): YAML 1.1 booleans and sexagesimal ints, `!!set`/`!!omap`
semantics, non-UTF-8 encodings other than via the `--input` BOM handling the reference has.

The official YAML test suite is an extra arbiter for the parser worker; failures there that the
reference shares are recorded, not fixed.

## 3. Node model

One class, `LTS\PhpXq\Yaml\Node` (mutable, public properties), mirrors go-yaml's `yaml.Node`, because
every yq operator (comments, style, tag, anchor, alias, line, kind, parent, path) is defined on that
structure. See the class for the field list; summary:

| Field                                        | Meaning                                                                          |
| -------------------------------------------- | -------------------------------------------------------------------------------- |
| `kind` (`NodeKindEnum`)                          | Document, Sequence, Mapping, Scalar, Alias                                       |
| `tag`                                        | resolved tag (`!!str`, `!!int`, `!!map`, custom `!x`); documents have `''`       |
| `tagExplicit`                                | the tag was written in the source (`!!str 12`); drives `tag` style output        |
| `style` (`NodeStyleEnum`)                        | Default (block/plain), Flow, SingleQuoted, DoubleQuoted, Literal, Folded         |
| `value`                                      | decoded scalar text; for an Alias, the anchor name                               |
| `content`                                    | children; Mapping is the flat list key0, value0, key1, value1...; Document has 1 |
| `anchor`                                     | `&name` defined on this node, or `''`                                            |
| `aliasTarget`                                | for an Alias, the anchored Node (shared reference)                               |
| `headComment`, `lineComment`, `footComment`  | comment text including the `#`, multiple lines joined with `\n`                  |
| `line`, `column`                             | 1-based source position, 0 for synthesised nodes                                 |
| `explicitStart`, `explicitEnd`, `directives` | document-level: `---`, `...` and `%` directive lines to re-emit                  |

Design choices:

- One class, no per-kind subclasses and no per-scalar typed value: a scalar stays a string plus a tag.
  Typed access (int, float, bool) is computed on demand by the evaluator from `tag` and `value`. This
  keeps a million-node document to one object per node and avoids parsing numbers nobody reads. A
  numeric operator parses once and the result is a new Node (so original formatting such as `0x1F`
  or `1.50` survives untouched nodes).
- Mapping `content` is flat, not a map of pairs: order is preserved, duplicate-key handling and
  key-node comments are free, and key lookup is a linear scan with an optional per-evaluation index
  the evaluator may build for large mappings.
- Anchors and aliases are object references; `Node::deepCopy()` re-points aliases that stay inside the
  copied tree and keeps aliases to outside targets. Nothing may serialise a Node tree with `serialize`
  or `json_encode`; a cycle is possible only through aliases and the emitter and evaluator guard it.
- Evaluator matches are `Yq\Runtime\Candidate` (node + parent + key + document index + file index +
  filename), which gives `path`, `key`, `parent`, `document_index`, `filename`, `file_index` and in-place
  assignment their context without back-pointers inside Node.

## 4. YAML pipeline

```
bytes --YamlTokenizer--> Token stream --YamlParser--> Document Nodes --YamlEmitter--> bytes
```

- `Yaml\Token\YamlTokenizerInterface::tokenize(string): iterable<Token>`. `TokenType` follows libyaml's
  scanner (stream, document, block/flow collection, key, value, entry, anchor, alias, tag, scalar) plus
  `Comment`. A hand-written scanner over the byte string using `strpos`/`strspn`/`preg_match` with
  `\G` anchors and offsets, no per-character objects, no substr copies in loops.
- `Yaml\Parser\YamlParserInterface::parse(string): iterable<Node>` yields `Document` nodes lazily. The
  parser worker may drive the tokenizer or scan directly (YAML's context sensitivity makes a fused
  scanner/parser legitimate); `YamlTokenizer` must still pass its own tests as the documented seam, and
  may be implemented by running the same scanner core and recording tokens.
- `Yaml\Emitter\YamlEmitterInterface::emit(Node, EmitOptions)` and `emitStream(iterable<Node>, ...)`.
  `EmitOptions` carries indent, colour, unwrapScalar, prettyPrint, noDocSeparator.
- Errors: `YamlSyntaxException` (line, column) from tokenizer and parser. The CLI prints
  `Error: yaml: line N: ...` and exits 1.

Comment attachment follows go-yaml v3 plus yq's pre/post-processing: a comment block directly above a
node is its head comment; text after a value on the same line is its line comment; a comment block after
the last entry of a collection, or at the end of a document, is a foot comment of the last node/
document; a blank line separates a head comment from the node below it in the way yq reproduces
(`header-processing-off.sh`, `leading-separator.sh` and `front-matter.sh` pin the edge cases).

Emitter fidelity rules: preserve style per node; indent sequences under their key by the configured
indent; keep flow collections on one line; re-quote only when the scalar cannot be written in its style;
preserve `---`/`...`; `-P` normalises styles but keeps comments; colour output follows yq's palette.

## 5. Expression language

mikefarah yq has its own jq-flavoured language; it is NOT jq (paths are updatable, `=` assigns, `|=`
updates, `*` merges, comments and styles are first-class). The jq evaluator of Plan 00003 is not reused:
the data model (comment-carrying nodes with identity, parents and paths) and the semantics (assignment
and update operate on the original document, results are node references, not values) differ at the
root. Plan 00003's JSON codec is likewise not reused by default (Section 7).

### 5.1 Syntax

Pipeline: `Yq\Expression\ExpressionLexerInterface::tokenize(string): list<ExpressionToken>` then
`ExpressionParserInterface::parse(string): ExpressionNodeInterface`. The parser is a precedence-climbing (Pratt)
parser, not a shunting yard.

Binding power, loosest to tightest (all left-associative unless noted):

| Level | Operators                                                                               |
| ----- | --------------------------------------------------------------------------------------- |
| 1     | `\|` pipe                                                                               |
| 2     | `,` union                                                                               |
| 3     | `=` `\|=` `+=` `-=` `*=` `/=` `%=` (right-associative)                                  |
| 4     | `//` alternative                                                                        |
| 5     | `or`                                                                                    |
| 6     | `and`                                                                                   |
| 7     | `==` `!=` `<` `<=` `>` `>=` (non-associative)                                           |
| 8     | `+` `-`                                                                                 |
| 9     | `*` (and `*+` `*?` `*d` `*n` `*c`), `/`, `%`                                            |
| 10    | juxtaposition: `.a style="x"`, `(.a \| key) line_comment="x"` (tighter than assignment) |
| 11    | postfix traversal: `.a`, `.[0]`, `.[]`, `.[1:3]`, `..`, `...`, trailing `?`             |

`X <word> = rhs` (for example `.a style="double"`, `.. line_comment=""`) is how the docs write
"set the style/tag/comment/anchor of `.a`": it parses as `Binary(Assign, Binary(Pipe, X, Call(word)), rhs)`. The lexer worker confirms the exact grammar against `cases.json` (grep for `style=`,
`line_comment=`, `tag=`, `anchor=`, `alias=`) and records any rule discovered in the parser's docblock.

Primary forms: literals (numbers incl. hex/octal, strings with `\(expr)` interpolation, `true`, `false`,
`null`), `.` and field paths (`.a`, `.a.b`, `."a b"`, `.["a"]`, `.a*` globs, `.[]`, `.[n]`, negative
indexes, `.[a:b]` slices), `..` and `...` (recursive descent without/with keys), `$var`, `( expr )`,
`[ expr ]` collect, `{ k: v, ... }` construct (with `{a}`-style shorthand desugared), function calls
`name`, `name(arg; arg)`, `if c then a elif c2 then b else d end`, `expr as $x | body`, `reduce src as $x (init; update)`, comments (`#` to end of line) skipped.

AST: closed set of immutable classes under `Yq\Expression\Ast` (Identity, Literal, VariableRef, Field,
Slice, Iterate, RecursiveDescent, Binary + `BinaryOperatorEnum`, Call, Collect, ObjectConstruct +
ObjectEntry, Conditional, Bind, Reduce, Interpolation). Index access `.[expr]` is `Field` with the
index expression as key. All named operators are `Call` nodes, so adding one never touches the AST.

### 5.2 Operator list (from the reference docs, `pkg/yqlib/doc/operators/`)

Each doc page is a conformance group; the evaluator worker implements them in this order of priority:

- Navigation: traverse-read, recursive-descent-glob, pipe, union, collect-into-array,
  create-collect-into-object, slice-array, parent, path, key, kind, line, column, document-index,
  file-operators (`filename`, `file_index`, `load`, `load_str`), env-variable-operators (`env`, `strenv`,
  `envsubst`), variable-operators (`as`, `ref`), `eval`, `with`, `reduce`, `if-then-else`.
- Selection and shape: select, filter, has, keys, length, pick, omit, delete, entries (`to_entries`,
  `from_entries`, `with_entries`), map, map_values, flatten, group-by, unique, sort, sort-keys, reverse,
  first, min, max, shuffle, pivot, array-to-map, contains, add, split-into-documents.
- Arithmetic and logic: add, subtract, multiply-merge (`*` with modifiers), divide, modulo, boolean-
  operators (`and`, `or`, `not`, `any`, `all`), compare, equals, alternative-default-value (`//`),
  `to_number`, `to_string`, `to_bool` style casts, `datetime` (`now`, `from_unix`, `to_unix`, `tz`,
  `format_datetime`, `with_dtf`), `string-operators` (`sub`, `test`, `match`, `capture`, `split`, `join`,
  `trim`, `upcase`, `downcase`, `ltrimstr`/`rtrimstr`, `contains`, `length`, `substr` family).
- Document metadata: anchor-and-alias-operators (`anchor`, `alias`, `explode`), comment-operators
  (`head_comment`, `line_comment`, `foot_comment`, `comments`), style, tag, `sort_by`, `unique_by`,
  `group_by`, `omit`, `shuffle`, `kyaml`.
- Assignment: assign-update (`=`, `|=`, `+=`, `-=`, `*=`, `/=`, `%=`), `.. | select | del`, `with`.
- Format operators (encode-decode family): `@base64`, `@base64d`, `@uri`, `@urid`, `@sh`, `@json`,
  `@yaml`, `@props`, `@csv`, `@tsv`, `@html`-style as listed in `encode-decode.md`, plus `from_json`,
  `to_json`, `from_yaml`, `to_yaml`, `from_csv`, `from_tsv`, `from_xml`, `to_xml`, `from_props`,
  `to_props`, `from_toml`-style, and the per-format pages (base64, base64url, csv-tsv, hcl, kyaml, lua,
  properties, shellvariables, toml, xml).
- `system-operators` (`system(cmd; args)`) is behind `--security-enable-system-operator`; env and file
  operators honour `--security-disable-env-ops` / `--security-disable-file-ops`.

The registry is open-ended: the worker enumerates the exact set from the pinned docs and the reference
source and records the final list in the evaluator's docblocks.

## 6. Evaluator

`Yq\Runtime\EvaluatorInterface::evaluate(ExpressionNodeInterface, EvaluationContext): list<Candidate>`.

- yq semantics: the context carries ALL current matches and each operator sees the whole list (so
  `collect`, `sort`, `add`, `first`, `reduce`, `group_by` work over a list), while most operators map over
  the list and concatenate. `|` evaluates the left side, then the right side with the left result as the
  new match list; `,` evaluates both against the same input and concatenates; `Binary` arithmetic and
  comparison are cross products as in the reference (`.a + .b` over multiple matches).
- Structural AST nodes are handled by the evaluator core; `Call` and `Binary` dispatch through
  `OperatorRegistryInterface` to `CallOperatorInterface` / `BinaryOperatorInterface` implementations in
  `src/Yq/Runtime/Operators/`, one class per operator or family, each declaring its names.
- Assignment (`=`, `|=`, `+=`...) evaluates the LHS path expression to candidates (which carry parent and
  key), evaluates the RHS, and mutates the matched nodes in place. The result is the (modified) original
  input, not the assigned values. Creating missing path elements (`.a.b.c = 1` on `{}`) follows the
  reference. `|=` evaluates the RHS once per matched node with that node as input.
- Alias handling follows the reference: reading through an alias traverses the target; `explode` and
  `*` merge resolve anchors; assignment through an alias edits the shared target.
- Variables and `reduce` extend `EvaluationContext::$variables`; `$ENV` is provided from the process
  environment; `$__loc__`-style extras only if the reference has them.
- Speed: results are plain PHP lists of small readonly `Candidate` objects; the evaluator avoids
  re-allocating contexts per node (one `withMatches` per pipeline stage, not per node), short-circuits
  single-match paths (`.a.b.c` on one document does no list merging), and constant-folds `Literal`s.
  Optimisation beyond that is Plan 00007.
- Errors raise `EvaluationException`; the CLI maps them to `Error: ...` on stderr and exit 1.

## 7. Relationship to Plan 00003 (jq)

Reuse is none by default: yq's `Node` carries comments, styles, tags, anchors and positions that a JSON
value model cannot, and the two expression languages differ in syntax, evaluation (node references with
paths versus pure values) and assignment. At the time of writing Plan 00003's skeleton (`src/Json`,
`src/Jq`) is not on main. A shared piece is justified only if a pure, dependency-free utility emerges
that both can call without either depending on the other's model, the realistic candidate being a
number-formatting/parsing helper. Until then `Yq\Format\Codec\JsonCodec` implements its own JSON
decode/encode over `Node` (it must preserve key order and number text, and emit yq's JSON layout), and
Plan 00007 may deduplicate. Record any reuse decision in this section.

## 8. Format conversion

`Yq\Format\Format` enumerates the reference's formats: yaml, json, props, csv, tsv, xml, toml, base64,
base64url, uri, shell, lua, kyaml, hcl (decode and/or encode as the reference supports:
`Format::canDecode()`/`canEncode()`; shell, lua and kyaml are output only).

- `DecoderInterface::decode(string, FormatOptions): iterable<Node>` yields Document nodes.
- `EncoderInterface::encode(Node, FormatOptions, int $resultIndex): string` writes one result including
  its newline; `$resultIndex` lets YAML emit `---` between results and CSV/TSV print a header once.
- `FormatRegistryInterface` resolves a codec per `Format`. `Yq\Format\FormatRegistry` constructs the
  codecs from `src/Yq/Format/Codec/`, one decoder and/or encoder class per format (`YamlCodec` wraps
  `YamlParserInterface`/`YamlEmitterInterface`).
- `FormatOptions` is the flat bag of every per-format switch (indent, colours, unwrapScalar, csv/tsv
  separators and auto-parse, properties separator and array brackets, XML attribute prefix/content
  name/skip flags, shell key separator, lua flags, merge-anchor fix).
- The same codecs back the operators (`from_json`, `@csv`, `to_xml`, `load` with a format) through
  `RuntimeServices::$formats`.

## 9. CLI

`Yq\Cli\YqApplicationInterface::run(array $args, $stdin, $stdout, $stderr): int`, created by
`FrontController` for `phpxq yq ...` (everything after `yq` is its argv). Exit codes: 0 success, 1 any
error (stderr `Error: <message>`), as the reference; 70 only while the skeleton stands.

Surface to implement (from the reference and `acceptance/*.sh`): default `eval` of the first non-flag
argument as the expression, with remaining arguments as files (stdin when none); subcommands `eval` (`e`),
`eval-all` (`ea`; all documents of all files as one context, `-n` null input, `-N` no document separator),
`completion`, `help`, `--version`; flags `-p/--input-format`, `-o/--output-format`, `-I/--indent`, `-P/--prettyPrint`,
`-i/--inplace`, `-n/--null-input`, `-e/--exit-status`, `-s/--split-exp` (split into files, with
`--split-exp-file`), `-C/-M` colours, `-N/--no-doc`, `-0/--nul-output`, `-j/--no-colors`,
`--front-matter`, `--header-preprocess`, `--unwrapScalar`, `--from-file`, `-f`, `--expression`,
`--arg`/`--argjson`-style `env` handling as the reference, `--security-*`, and the per-format flags
(`--csv-auto-parse`, `--csv-separator`, `--tsv-auto-parse`, `--properties-separator`,
`--properties-array-brackets`, `--xml-*`, `--shell-key-separator`, `--lua-*`, `--yaml-fix-merge-anchor-to-spec`).
Input reading, in-place editing (write via temp file then rename, only when the output differs) and
multi-file context assembly (`file_index`, `document_index`) live here. The CLI owns the wiring:
construct `YamlParser`, `YamlEmitter`, `ExpressionParser`, `Evaluator`, `FormatRegistry` and
`RuntimeServices`. The skeleton's classes already exist with those names and no-argument constructors.

## 10. File ownership map

Each worker creates and edits only the files listed for them. No worker edits another worker's files;
the frozen files change only through the orchestrator. Shared helper needs are met by adding a file in
your own directory, never by editing a neighbour's. Every worker adds its tests under the mirrored
`tests/Unit/...` path.

| Worker                     | Owns (src)                                                                                                                                     | Owns (tests)                                        |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| W1 YAML parser             | `src/Yaml/Parser/YamlParser.php` and any new file in `src/Yaml/Parser/`; `src/Yaml/Token/YamlTokenizer.php` and new `src/Yaml/Token/*` helpers | `tests/Unit/Yaml/Parser/`, `tests/Unit/Yaml/Token/` |
| W2 YAML emitter            | `src/Yaml/Emitter/YamlEmitter.php` and any new file in `src/Yaml/Emitter/`                                                                     | `tests/Unit/Yaml/Emitter/`                          |
| W3 expression lexer/parser | `src/Yq/Expression/ExpressionLexer.php`, `src/Yq/Expression/ExpressionParser.php` and new files in `src/Yq/Expression/Parser/`                 | `tests/Unit/Yq/Expression/`                         |
| W4 evaluator and operators | `src/Yq/Runtime/Evaluator.php`, `src/Yq/Runtime/OperatorRegistry.php` and new files in `src/Yq/Runtime/` and `src/Yq/Runtime/Operators/`       | `tests/Unit/Yq/Runtime/`                            |
| W5 format codecs           | `src/Yq/Format/FormatRegistry.php` and new files in `src/Yq/Format/Codec/`                                                                     | `tests/Unit/Yq/Format/`                             |
| W6 CLI                     | `src/Yq/Cli/YqApplication.php` and new files in `src/Yq/Cli/`                                                                                  | `tests/Unit/Yq/Cli/`                                |

Frozen (changes only via the orchestrator, because several workers depend on them):

- `src/Yaml/Node.php`, `NodeKindEnum.php`, `NodeStyleEnum.php`, `Schema/CoreSchema.php`,
  `Exception/YamlSyntaxException.php`, `Token/Token.php`, `Token/TokenType.php`,
  `Token/YamlTokenizerInterface.php`, `Parser/YamlParserInterface.php`, `Emitter/EmitOptions.php`,
  `Emitter/YamlEmitterInterface.php`
- `src/Yq/Expression/ExpressionNodeInterface.php`, `ExpressionToken.php`, `ExpressionTokenKindEnum.php`,
  `ExpressionLexerInterface.php`, `ExpressionParserInterface.php`, `ExpressionSyntaxException.php`,
  everything under `src/Yq/Expression/Ast/`
- `src/Yq/Runtime/Candidate.php`, `EvaluationContext.php`, `EvaluationException.php`,
  `EvaluatorInterface.php`, `CallOperatorInterface.php`, `BinaryOperatorInterface.php`,
  `OperatorRegistryInterface.php`, `RuntimeServices.php`, `SecurityOptions.php`
- `src/Yq/Format/Format.php`, `FormatOptions.php`, `FormatException.php`, `DecoderInterface.php`,
  `EncoderInterface.php`, `FormatRegistryInterface.php`
- `src/Yq/Cli/YqApplicationInterface.php`, `src/Cli/FrontController.php` (the one-line yq delegate)

A frozen file that is genuinely wrong or too narrow: the worker records the needed change in the
plan's JOURNAL and works around it locally until the orchestrator applies it between rounds.

Seams between workers (what each may assume of the others, all via interfaces):

- W3 produces `ExpressionNodeInterface` trees; W4 consumes them. Their only shared artefact is the frozen AST.
- W4 uses `YamlParserInterface`, `FormatRegistryInterface` and `ExpressionParserInterface` only through
  `RuntimeServices`; unit tests construct fakes of these interfaces, never the other workers' classes.
- W5's YAML codec uses `YamlParserInterface`/`YamlEmitterInterface` by interface.
- W6 wires concrete classes by name (they exist as skeleton stubs) and tests the CLI end to end through
  `CliRunner`; it may rely on the others' final behaviour only for conformance runs, not its unit tests.
- Integration order once all six land: W1+W2 (round-trip), then W3+W4 (read-only expressions), then W5,
  then W6 conformance runs drive triage.

## 11. Performance notes

- Parsing and emitting dominate real use. Scan with `strcspn`/`strpos`/`preg_match` using offsets and
  `\G`; avoid `substr` per character, `str_split`, and per-token arrays in the hot path.
- Build exactly one `Node` per YAML node; share the empty `content` array; never copy strings
  needlessly.
- The emitter writes into a list of string parts joined once.
- Evaluator: see Section 6. Benchmarks arrive in Plan 00005; tuning in Plan 00007.
