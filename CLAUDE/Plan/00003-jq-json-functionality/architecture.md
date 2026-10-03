# Plan 00003 architecture: the jq implementation

Supporting document for [PLAN.md](PLAN.md). It is the contract the parallel workers build against. The
skeleton under `src/Json`, `src/Jq` and the tests under `tests/Unit/{Json,Jq}` are its executable form: when
this document and the interfaces disagree, fix the document.

## Target

- Reference implementation: jq **1.8.2** (tag `jq-1.8.2`, commit `34f7186b86743a083a589741b6cea95293524108`),
  pinned in `tests/Conformance/Jq/fixtures/NOTICE.md`. Every behavioural question is answered by that
  version's manual, `jq.test`, `man.test` and the shell tests, not by the jq installed on a developer machine
  (the sandbox ships 1.6, which differs in number printing, `ltrimstr`, `limit`, `@base32d`, `ltrimstr`,
  `abs`, `toarray`, `trim` and more).
- PHP 8.5, no production Composer dependency, no extensions beyond those PHP always ships with
  (`mbstring` is not assumed; `pcre` and `ctype` are core).
- Conformance is measured through `bin/phpxq jq ...` by the Plan 00002 harness; `known-gaps.txt` is the only
  place a failure may be excused.

## Pipeline

```text
jq source --Lexer--> Token[] --Parser--> Ast\Program --Compiler--> CompiledProgram
                                                                       |
JSON text --JsonDecoder--> values --(per input)--> run(ctx, input, emit)
                                                                       |
                                          emit(value) --JsonEncoder--> bytes on stdout
```

| Stage    | Contract                                                         | Skeleton file                          |
| -------- | ---------------------------------------------------------------- | -------------------------------------- |
| Lexer    | `Parser\LexerInterface::tokenize(string): list<Token>`           | `src/Jq/Parser/LexerInterface.php`     |
| Parser   | `Parser\ParserInterface::parse(string): Ast\Program`             | `src/Jq/Parser/ParserInterface.php`    |
| Compiler | `Runtime\CompilerInterface::compile(Program, globals): Compiled` | `src/Jq/Runtime/CompilerInterface.php` |
| Run      | `CompiledProgram::run(RuntimeContext, input, emit): void`        | `src/Jq/Runtime/CompiledProgram.php`   |
| Decode   | `Json\JsonDecoderInterface`                                      | `src/Json/JsonDecoderInterface.php`    |
| Encode   | `Json\JsonEncoderInterface`                                      | `src/Json/JsonEncoderInterface.php`    |
| CLI      | `Cli\JqApplication::run(args, stdin, stdout, stderr): int`       | `src/Jq/Cli/JqApplication.php`         |

The lexer and parser know nothing about evaluation, the compiler knows nothing about text. That keeps the
parser reusable by Plan 00004 if yq's expression language turns out to share jq syntax: only the evaluator
side would then be swapped, or extended with YAML-specific builtins through the same
`BuiltinRegistry`.

Errors cross stage boundaries as exceptions, all in `src/Jq/Runtime` or `src/Json`:

| Exception                    | Meaning                                            | Exit code        |
| ---------------------------- | -------------------------------------------------- | ---------------- |
| `Json\JsonSyntaxException`   | invalid JSON text                                  | 2 (5 mid-stream) |
| `Runtime\JqCompileException` | lex, parse, name-resolution or import error        | 3                |
| `Runtime\JqException`        | jq runtime error carrying a jq value               | 5                |
| `Runtime\BreakException`     | `break $label` and native early stop; not an error | never surfaces   |
| `Runtime\HaltException`      | `halt`, `halt_error`                               | as requested     |

## Lexer and parser

- `Token(type, text, line, column)`; `TokenType` enumerates every kind and documents what `text` holds.
- String literals are a token sequence (`StringStart`, `StringFragment`\*, `InterpStart` ... `InterpEnd`,
  `StringEnd`) so that interpolations nest arbitrarily; the lexer counts parentheses to find the `)` that ends
  an interpolation. Escapes (`\n`, `\uXXXX` including surrogate pairs, `\/`, `\(`) are resolved by the lexer.
- Keywords are real token types, but after a `.` they lex as `Field` (`.and`) and the parser accepts any
  keyword token as an object key (`{if: 1}`). `not` is an ordinary function, not a keyword.
- The parser is hand written recursive descent with precedence climbing, following the grammar of jq 1.8's
  `parser.y` (precedence list on `ParserInterface`). It performs all desugaring documented on the AST classes
  (object shorthand, `..`, `elif`, postfix `?`, `$__loc__`), so the AST is small and the compiler has fewer
  cases.
- Syntax errors are `JqCompileException` with jq's wording (`syntax error, unexpected ... (Unix shell quoting issues?) at <top-level>, line N:`); the CLI prints them and `jq: 1 compile error`.

### AST node set (`src/Jq/Ast`)

All nodes are `final readonly` value classes implementing `Node` (marker) except where noted. Patterns
implement `Pattern`.

| Group       | Classes                                                                                                                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------ |
| Program     | `Program` (imports, module directive, top-level defs, optional body), `ImportDirective`, `ImportKind`, `ModuleDirective` |
| Terms       | `Identity`, `Literal`, `NumberLiteral`, `Format`, `StringInterpolation`, `Variable`, `Location`                          |
| Postfix     | `Index`, `Slice`, `Iterate`, `TryCatch` (also postfix `?`)                                                               |
| Construct   | `ArrayConstruct`, `ObjectConstruct`, `ObjectEntry` (not a Node)                                                          |
| Combinators | `Pipe`, `Comma`, `Negate`, `Binary` + `BinaryOp`, `Assign` + `AssignOp`, `IfThenElse`                                    |
| Binding     | `Bind` (`as`, with `?//` alternatives), `VariablePattern`, `ArrayPattern`, `ObjectPattern`, `ObjectPatternEntry`         |
| Loops       | `Reduce`, `ForeachLoop`, `Label`, `BreakOut`                                                                             |
| Functions   | `FuncDef` (not a Node), `FuncDefScope` (nested `def ...; rest`), `FunctionCall`                                          |

`Foreach` and `Break` are reserved words in PHP, hence `ForeachLoop` and `BreakOut`. `FunctionCall`,
`Variable`, `BreakOut` and `FuncDef` carry a line for compile-error messages.

## Value model (`src/Json`)

| jq      | PHP                                    | Notes                                                     |
| ------- | -------------------------------------- | --------------------------------------------------------- |
| null    | `null`                                 |                                                           |
| boolean | `bool`                                 |                                                           |
| number  | `int`, `float` or `Json\PreciseNumber` | see number semantics                                      |
| string  | `string` (UTF-8, always valid)         | invalid bytes are replaced by U+FFFD when decoding        |
| array   | `list<mixed>` (a PHP list array)       | PHP copy-on-write gives value semantics for free          |
| object  | `Json\JsonObject` (immutable)          | insertion ordered; keys are always PHP strings to callers |

Why not PHP arrays or `stdClass` for objects: an empty PHP array cannot be both `[]` and `{}`, and a PHP array
turns the key `"1"` into the int `1`. `JsonObject` wraps an array whose keys are cast back with `(string)` on
every read; the cast is lossless because PHP only converts canonical decimal strings, so `"01"`, `"1.5"` and
`"-0"` stay strings and `"1"` round-trips. Insertion order is preserved (jq prints objects in insertion order,
and `keys`, `-S` and comparison sort by codepoint). `Json\Values` provides `typeName`, `isTruthy` and jq's
total order (`compare`, `equals`), implemented and tested in the skeleton so that every worker sorts the same
way.

A PHP list array always has keys `0..n-1`; anything that builds an array must keep that invariant
(`array_values` after removal), because `\is_array` is the type test for jq arrays.

## Number semantics decision

Recorded in the plan's Technical Decisions; summarised here for the codec and evaluator workers. Source of
truth is the 1.8.2 behaviour exercised by `jq.test` ("When no arithmetic is involved jq should preserve the
literal value", "decnum to double conversion", `1E+1000`, `abs`, `length`, `tojson` cases, all guarded by
`have_decnum`; this implementation behaves as a decNumber build, `have_decnum` is true).

1. **Computation is IEEE double.** Any arithmetic, `floor`, `sqrt`, `tonumber`, comparison ordering and so on
   converts to double. Results are PHP `int` when integral and `|n| <= 2^53`, otherwise `float`. (Ints beyond
   2^53 are never produced by arithmetic, so `int` arithmetic cannot silently diverge from double results;
   overflow is checked and falls back to float.)
2. **Literal preservation.** A number that enters the program unchanged (decoded from input, written as a
   literal in the program, passed through `.`, `tojson`, `tostring`, array/object storage, `-.`, `abs`,
   `length`) prints with its canonical literal when the double would not reproduce it. The codec represents
   such a value as `PreciseNumber(float $value, string $literal)`; the decoder and `NumberParser::parse`
   (shared with the compiler for `NumberLiteral`) create it only when the literal is not exactly what the
   double prints, so ordinary numbers stay plain `int`/`float` and fast. Canonical form: no leading `+`, no
   leading zeros, exponent written `E+1000` as jq prints it, trailing zeros of a decimal fraction dropped only
   as decNumber does (the codec worker pins the exact rule against `jq.test`/`man.test`).
3. **Equality and ordering of precise numbers** follow the double (`13911860366432393 == 13911860366432392` is
   true in a build without decNumber but the suite expects false with decNumber: the codec worker must make
   `Values::compare` consult the literal when both operands are `PreciseNumber`/large ints; the skeleton's
   `Values::compare` compares doubles and is the single place to extend).
4. **Output formatting of doubles.** `tojson`/output of a non-integral or large float uses the shortest digit
   string that round-trips (PHP `serialize_precision = -1`, i.e. `var_export`/`json_encode` precision), laid
   out by jq 1.8's `jvp_dtoa_fmt` rules: integral values print without a fraction or exponent up to 17
   significant digits; otherwise scientific notation with a signed two-digit minimum exponent (`1e-05`,
   `1.5e+300`); `nan` prints `null`; `infinite` prints `1.7976931348623157e+308` (negative with a minus). The
   exact switch points are pinned by the encoder worker with tests derived from `jq.test`, `man.test` and the
   shell tests, not from the sandbox's jq 1.6.
5. **Integer-valued floats** (`3.0`, `1e2`) print as integers; `-0` prints `-0`.
6. `tostring`, `tojson`, string interpolation, `@text`, `@json` and the output stage all go through the single
   encoder so there is one formatting implementation.

## Evaluator model

### Decision: compile the AST to PHP closures in continuation-passing (push) style

Each AST node compiles once to an object implementing `Runtime\Filter`:

```php
public function run(mixed $input, Closure $emit): void;                       // value mode
public function paths(?array $path, mixed $input, Closure $emit): void;      // path mode
```

A filter calls `$emit($output)` for each output, in order, and returns when exhausted. A generator `a, b` is
`a->run($in, $emit); b->run($in, $emit);`. A pipe `a | b` is `a->run($in, fn($x) => b->run($x, $emit))`.
Backtracking is the call stack unwinding after `$emit` returns; there is no explicit choice-point stack.

Why this over PHP `Generator`s, over a bytecode VM like jq's, and over an AST-walking interpreter:

- **Speed.** A `yield from` chain costs a generator object and a resume per output per nesting level; a
  closure call is the cheapest dispatch PHP has. CPS removes all per-output allocation. Plan 00007 (performance)
  and the risks table both name the backtracking evaluator as the hot spot, so the architecture must not
  block it. The compile step pays for itself by resolving names, arity and slots once per program instead of
  per input value.
- **Early termination is cheap and explicit.** `limit`, `first`, `isempty`, `label/break` throw a private
  `BreakException` caught at the owner; `try` does not catch it. With generators the same needs
  `->return()`/`finally` plumbing at each level.
- **Errors are plain PHP exceptions** unwinding the same stack, matching jq's error propagation, and `try`
  is a `try/catch` around the body's `run` that re-throws errors raised by the continuation after the body
  emitted (jq semantics: an error in `$emit` is not the body's error, so the continuation call is wrapped so
  its exceptions are tagged and not swallowed).
- **Costs accepted.** Deep recursion (`range(1e6)` inside `reduce` is fine because the stack depth follows
  program nesting, not output count; but `def f: if . < 1e5 then .+1|f else . end` nests 1e5 PHP frames,
  which PHP survives because it has no fixed C stack limit for userland calls, only `memory_limit`). jq's
  tail-call optimisation is not replicated initially; if the suite demands it the compiler can trampoline
  tail calls of recursive `def`s without changing the interfaces.
- `input`/`inputs`/`limit`-style laziness works because the source of inputs is pulled by the builtin, not
  pushed.

The evaluator-core worker may use any internal structure (an `Env` frame class, closure objects per node) as
long as the public surface in `src/Jq/Runtime` stays as specified. Variables and function parameters are
resolved at compile time to slot indices in a frame array; closure parameters are `Filter`s bound to their
defining frame, so a call `f(g)` binds `g` without copying.

### Name resolution

Order for `name/arity`: lexical `def`s innermost first (including parameters, closures), then top-level defs
of imported modules (`alias::name`), then the **prelude** (jq-defined builtins), then **native** builtins.
Missing names are `JqCompileException("f/0 is not defined at <top-level>, line N:")`. The prelude is parsed
once per `Compiler` and each prelude `def` is compiled lazily on first reference (a program that never calls
`walk` never compiles it), which keeps start-up cost low for the CLI where most programs are tiny.

### Path expressions

`path(f)`, assignment (`=`, `|=`, `+=` ...), `del`, `to_entries`-style updates, `paths`, `getpath` in path
position, `limit`/`first`/`recurse` in path position all need the *paths* of the values `f` produces. Path
mode is a second method on every `Filter`, not a separate interpreter, so the two cannot drift:

- `paths(?array $path, $input, $emit)`: `$path` is the path of `$input` from the start of the path expression
  (a list of string keys, int indexes, or slice objects), `null` meaning "`$input` was computed, not reached
  by a path". `Identity` re-emits `($path, $input)`. `Index`/`Slice`/`Iterate` extend the path (and raise
  `Invalid path expression with result ...` when `$path` is null, but only when they would actually index, as
  jq does: `path(1|empty)` is fine). `Pipe` threads path and value. `Comma`, `IfThenElse`, `Alt`, `TryCatch`,
  `Reduce`, `ForeachLoop`, `Label`, function calls and closure parameters propagate. Literals, arithmetic,
  constructors and `ValueBuiltin`s emit `(null, value)`.
- A `FuncDef` body is compiled once; both modes are available on the resulting `Filter`.
- Assignment is defined on top of paths exactly as jq's builtins: `lhs = rhs` is `reduce path(lhs) as $p (.; setpath($p; $v))` per `$v` of `rhs`; `lhs |= f` is `reduce path(lhs) as $p (.; setpath($p; getpath($p) | f))`
  with the 1.8 rule that an empty `f` deletes the path (implemented with `label`/`delpaths`); arithmetic
  updates (`+=`) evaluate `rhs` once against `.` and apply per path. The shared primitives are in
  `Runtime\PathOps` (`getPath`, `setPath`, `deletePaths`) and used by both the evaluator and the `getpath`,
  `setpath`, `delpaths`, `del`, `to_entries` natives.

### Operators

`Runtime\Arithmetic` implements `+ - * / %` and negation with jq's exact type table and error messages
(`number (1) and string ("a") cannot be added`; object `*` recursive merge; string repeat and split-by `/`;
array `-`; `null` as identity for `+`). Comparison and ordering use `Json\Values::compare`. Evaluation order
for binary operators follows jq: the right operand is the outer loop (`[(1,2) + (10,20)]` is
`[11,12,21,22]`). `and`/`or`/`//` short-circuit and are generators.

### Errors, `try`, `label`

- `error(v)` throws `JqException($v)`; builtin failures use `JqException::fromMessage`. `try f catch g` runs
  `g` with the error value (`error(null)` caught by `try` yields `null` per 1.8). `try f` without catch, and
  postfix `?`, swallow. `error` with the special `{__jq: n}` break marker from the reference implementation is
  not reproduced: `BreakException` carries the label object directly.
- `label $x | body`: creates a fresh label token, binds it in the frame, runs `body` inside
  `try { } catch (BreakException $e) { if ($e->label !== $token) throw $e; }`. `break $x` throws.
- `limit(n; f)`, `first(f)`, `isempty(f)`, `until`-style natives use the same mechanism with a private token.
- `halt`/`halt_error` throw `HaltException`, which no jq construct can catch; the CLI turns it into the exit
  status.
- Uncaught `JqException` reaches `JqApplication`, which prints `jq: error (at <file>:<n>) : message` (or
  `(not a string): <json>` for non-string values) and sets exit status 5 after finishing other inputs,
  exactly as jq.

## Builtins

Two kinds, one registry (`Runtime\BuiltinRegistry`, array-backed `DefaultBuiltinRegistry`):

1. **Native PHP builtins**, registered by `name/arity`:
   - `ValueBuiltin::call(RuntimeContext, input, list<mixed> args): mixed`. The compiler evaluates the argument
     filters as nested loops (last argument outermost, first innermost: jq's cfunction convention) and calls
     once per combination. This is the fast path for `length`, `keys`, `has`, `contains`, `ltrimstr`, `test`,
     `strftime`, math, `tojson`, `tostring`, `implode`, `splits`... anything that is a pure function.
   - `StreamBuiltin::run(ctx, input, list<Filter> args, emit)` for builtins that take closure parameters or emit
     0..n outputs: `empty`, `error`, `range/2`, `limit`, `first/1`, `isempty`, `input`, `inputs`, `path/1`,
     `recurse/1`, `env`, `getpath` ...
   - `PathStreamBuiltin::runPaths` in addition, for natives valid inside a path expression (`getpath`, `empty`,
     `error`, `first/1`, `limit`, `select` when native, `recurse`).
2. **jq-defined builtins** in the prelude, plain jq source of `def`s (`map`, `select`, `recurse`, `to_entries`,
   `from_entries`, `with_entries`, `walk`, `paths`, `leaf_paths`, `any`, `all`, `flatten`, `env`-free helpers,
   `todate`, `splits`, `ascii_downcase` wrappers, `@sh`-free sugar, `getpath/1` fallbacks...). Rule of thumb: a
   builtin is native when it is a hot path or needs PHP (regex engine, date functions, math, string
   primitives, sorting, `tojson`), jq-defined when jq's own definition is short and already correct, because it
   inherits path-mode and generator semantics for free and stays diff-able against jq's `builtin.jq`.

Provider layout (each file has one owner, see the ownership map):

```text
Builtin\StandardBuiltins   FROZEN, lists the three providers, create(): BuiltinRegistry
Builtin\CoreBuiltins       builtins worker: everything except regex and date; prelude in prelude.jq
Builtin\RegexBuiltins      regex/date worker: match/test/capture/scan/split/sub/gsub, Oniguruma -> PCRE translation
Builtin\DateBuiltins       regex/date worker: mktime/gmtime/localtime/strftime/strptime/todate/fromdate...
```

Each provider's `registerInto(BuiltinRegistry)` calls `register()` for natives and `addPrelude()` for jq
source; preludes concatenate in provider order (core first, so regex/date preludes may use core defs).
Duplicate `name/arity` registrations throw, so two owners cannot silently shadow each other.

Oniguruma versus PCRE: the regex worker owns a translation layer (named groups, `\h`, `\H`, POSIX brackets,
`(?<name>...)` vs PCRE syntax, flags `g i x s n l p`, byte versus codepoint offsets: jq reports codepoint
offsets, so offsets are converted from PCRE's byte offsets). Unsupported constructs raise a jq error with a
documented message and are listed in `known-gaps.txt` with justification (Plan 00002 mechanism).

## Modules

`Runtime\ModuleLoaderInterface` (`FileModuleLoader`, evaluator-core worker, Task 2.11) implements jq's
search rules: the directive's `"search"` metadata (relative to the importing file), the `-L` paths,
`~/.jq` (file or directory), `$ORIGIN/../lib/jq` and `$ORIGIN/../lib`, `foo/foo.jq` for a bare `foo`, cyclic
import detection, `.json` data files for `import "f" as $name` (bound as an array of all values),
`include` splicing defs unprefixed, `module {...}` metadata, and `modulemeta`/`get_search_list`. The compiler
asks the loader for a parsed `LoadedModule`, compiles it in its own scope and exposes its defs under the alias
prefix. The fixtures live in `tests/Conformance/Jq/modules/`.

## CLI and exit codes

`Cli\JqApplication::run(array $args, $stdin, $stdout, $stderr): int` is wired from
`Cli\FrontController` for the tool `jq` (arguments after `jq`). It owns, in order: option parsing (`-n -r -j -a -s -c -C -M -S -e -f --tab --indent n --arg --argjson --slurpfile --rawfile --args --jsonargs --seq --stream --stream-errors -R -L --raw-output0 --binary -h -V --build-configuration` and combined short flags), reading the
program (inline, `-f file`, `--from-file`), building `RuntimeContext` (`$ENV`, `$__prog_args`, `$ARGS`, named
arguments), compiling with the `Cli\CompilerFactoryInterface` (so the `-L` paths reach the module loader),
decoding inputs lazily (`JsonDecoderInterface::decodeAll`) from files or stdin, running the program per input,
encoding each output with `JsonEncoderInterface` (`EncodeOptions`: indent, tab, sort keys, ASCII, `ColorScheme`
from `JQ_COLORS`/`NO_COLOR`), and the trailing separators (`--seq` RS, `--raw-output0` NUL, `-j` none).

Exit codes (`Cli\JqExitCode`): 0 success; 1 with `-e` when the last output was `false`/`null`; 2 usage or
system error (also the message `Usage:\tjq [OPTIONS] FILTER [FILES...]`); 3 compile error; 4 with `-e` and no
output; 5 uncaught runtime error or invalid JSON input. `halt`/`halt_error` set their own status. Until the CLI
worker lands the application returns 70 with `jq: not implemented`, the code the harness already expects.

## File ownership map

Seven workers run in parallel; each edits only its own paths, so merges do not conflict. FROZEN files are the
contract: only the orchestrator changes them (a worker that needs a contract change reports it instead).
Stub classes in the skeleton exist so everything compiles; the owner replaces the stub in place, keeping the
constructor and method signatures.

| Worker (task)                    | Owns (source)                                                                                                    | Owns (tests)                                                                                             |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| 1 Lexer (2.2)                    | `src/Jq/Parser/Lexer.php`, `src/Jq/Parser/Lexer/**`                                                              | `tests/Unit/Jq/Parser/Lexer*`                                                                            |
| 2 Parser (2.2)                   | `src/Jq/Parser/Parser.php`, `src/Jq/Parser/Parser/**`                                                            | `tests/Unit/Jq/Parser/Parser*`                                                                           |
| 3 Evaluator core (2.3-2.8, 2.11) | `src/Jq/Runtime/Compiler.php`, `Arithmetic.php`, `PathOps.php`, `FileModuleLoader.php`, `src/Jq/Runtime/Eval/**` | `tests/Unit/Jq/Runtime/Eval/**`, `CompilerTest`, `ArithmeticTest`, `PathOpsTest`, `FileModuleLoaderTest` |
| 4 JSON codec (2.1)               | `src/Json/JsonDecoder.php`, `JsonEncoder.php`, `NumberParser.php`, `src/Json/Codec/**`                           | `tests/Unit/Json/{JsonDecoder,JsonEncoder,NumberParser}Test.php`, `tests/Unit/Json/Codec/**`             |
| 5 Builtins (2.9)                 | `src/Jq/Builtin/CoreBuiltins.php`, `src/Jq/Builtin/Core/**`, `src/Jq/Builtin/prelude.jq`                         | `tests/Unit/Jq/Builtin/Core*`, `tests/Unit/Jq/Builtin/Core/**`                                           |
| 6 Regex and date builtins (2.10) | `src/Jq/Builtin/RegexBuiltins.php`, `DateBuiltins.php`, `src/Jq/Builtin/Regex/**`, `src/Jq/Builtin/Date/**`      | `tests/Unit/Jq/Builtin/{Regex,Date}*`, `.../Regex/**`, `.../Date/**`                                     |
| 7 CLI (3.1-3.3)                  | `src/Jq/Cli/JqApplication.php`, `src/Jq/Cli/**` except the frozen pair, `scripts/` only if needed                | `tests/Unit/Jq/Cli/JqApplication*`, `tests/Unit/Jq/Cli/Options/**`                                       |

FROZEN (orchestrator only):

- `src/Jq/Ast/**` (all node classes), `src/Jq/Parser/{Token,TokenType,LexerInterface,ParserInterface}.php`
- `src/Jq/Runtime/{Filter,CompiledProgram,CompilerInterface,RuntimeContext,InputProviderInterface,Builtin,ValueBuiltin,StreamBuiltin,PathStreamBuiltin,BuiltinRegistry,BuiltinProvider,DefaultBuiltinRegistry,ModuleLoaderInterface,LoadedModule}.php`
- `src/Jq/Runtime/{JqException,JqCompileException,BreakException,HaltException}.php`
- `src/Json/{JsonObject,PreciseNumber,Values,ColorScheme,EncodeOptions,JsonSyntaxException,JsonDecoderInterface,JsonEncoderInterface}.php`
- `src/Jq/Builtin/StandardBuiltins.php`, `src/Jq/Cli/{CompilerFactoryInterface,DefaultCompilerFactory,JqExitCode}.php`
- `src/Cli/FrontController.php`, `tests/Conformance/**`, `tests/Support/**`, every `known-gaps.txt`, `CLAUDE/Plan/README.md`
  and every plan's Status line.

Cross-worker dependencies, so ordering is clear:

- The parser worker tests against hand built `Token` lists or a fake `LexerInterface` until the lexer lands.
- The evaluator worker tests against ASTs built by hand (the AST classes are final and trivial to construct) and
  a fake `BuiltinRegistry`/`RuntimeContext`, so it needs neither parser nor builtins to start.
- The builtins workers test natives by calling `ValueBuiltin::call` / `StreamBuiltin::run` directly with
  `Filter` fakes; prelude text is verified end to end only after the evaluator, parser and lexer have merged.
- The CLI worker tests with a fake `CompilerFactoryInterface` and a fake decoder/encoder until the real ones
  merge.
- End-to-end checks run through `php scripts/conformance-report.php jq` once everything is merged; the
  orchestrator runs the first full pass.

Merge-conflict hotspots deliberately avoided: no worker appends to a shared registry file (providers are
separate classes), no shared "all builtins" class, one prelude file owned by one worker (regex and date add
their jq-defined helpers from PHP via `addPrelude()` in their own provider), and tests are per owner directory.
