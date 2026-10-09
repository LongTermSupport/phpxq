# Using phpxq as a library

phpxq is a command-line tool, but it is also an ordinary Composer package. Another project can require it
and run jq programs and yq expressions from PHP code, with no process spawned and no binary installed.

Every PHP sample on this page is executed by `tests/Unit/LibraryDocsTest.php`, and its output is the
`text` block beneath it.

## Requirements and install

- PHP `^8.5` with `ext-ctype`, `ext-json` and `ext-mbstring`. There are no other runtime dependencies.

```bash
composer require lts/phpxq
```

The package is of type `library`. Composer also exposes the command-line tool as `vendor/bin/phpxq`.

## Public API

These classes are the supported surface. Their signatures follow Semantic Versioning.

| Class                            | Use                                                              |
| -------------------------------- | ---------------------------------------------------------------- |
| `LTS\PhpXq\Json\JsonDecoder`     | JSON text to PHP values (`decodeOne`, `decodeAll`)               |
| `LTS\PhpXq\Json\JsonEncoder`     | PHP values to JSON text, with `LTS\PhpXq\Json\EncodeOptions`     |
| `LTS\PhpXq\Json\JsonObject`      | The PHP value of a JSON object (keeps key order and key types)   |
| `LTS\PhpXq\Jq\Jq`                | `Jq::run($program, $input, $variables)`: run a jq program        |
| `LTS\PhpXq\Yq\Yq`                | `Yq::evaluate($expression, $document, ...)`: run a yq expression |
| `LTS\PhpXq\Yq\Format\FormatEnum` | Names the input and output formats of `Yq::evaluate`             |

The exceptions each call raises are listed under [Errors](#errors).

Everything else under `LTS\PhpXq\` (the lexers, parsers, evaluators, emitters, `Cli` classes, `Runtime`
classes and the rest) is internal. It is not covered by the stability promise and may change or move in any
release, so do not construct or extend it. The sole exceptions are the exception classes named below.

## JSON

JSON arrays decode to PHP lists, objects to `JsonObject`, and numbers too large for `int` or `float` keep their
exact digits. A `JsonObject` is used instead of an array so that keys such as `"0"` and an empty `{}` survive a
round trip.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;

$value = new JsonDecoder()->decodeOne('{"name": "phpxq", "tags": ["jq", "yq"], "big": 12345678901234567890}');

echo $value->get('name'), "\n";
echo new JsonEncoder()->encode($value, EncodeOptions::compact()), "\n";
echo new JsonEncoder()->encode($value, new EncodeOptions(indent: 2, sortKeys: true)), "\n";

foreach (new JsonDecoder()->decodeAll("1 [2]\n{\"a\": 3}") as $item) {
    echo new JsonEncoder()->encode($item, EncodeOptions::compact()), "\n";
}
```

```text
phpxq
{"name":"phpxq","tags":["jq","yq"],"big":12345678901234567890}
{
  "big": 12345678901234567890,
  "name": "phpxq",
  "tags": [
    "jq",
    "yq"
  ]
}
1
[2]
{"a":3}
```

## jq

`Jq::run()` compiles a program, runs it over one input value and returns the list of every output. Input is
anything the decoder produces, or any PHP `null`, `bool`, `int`, `float`, `string` or list; build an object with
`JsonObject::fromPairs()`. Named variables are passed as the third argument and read as `$name`.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;

$input = new JsonDecoder()->decodeOne('{"items": [{"name": "a", "n": 1}, {"name": "b", "n": 2}, {"name": "c", "n": 3}]}');

$names = Jq::run('.items[] | select(.n > 1) | .name', $input);
echo implode(',', $names), "\n";

[$total] = Jq::run('[.items[].n] | add', $input);
echo $total, "\n";

[$summary] = Jq::run('{count: (.items | length), above: [.items[] | select(.n > $min) | .name]}', $input, ['min' => 1]);
echo new JsonEncoder()->encode($summary, EncodeOptions::compact()), "\n";
```

```text
b,c
6
{"count":3,"above":["b","c"]}
```

`halt` ends a run quietly and keeps the outputs so far; `halt_error` raises a `JqException` holding its message.
`input` fails and `inputs` is empty, and `debug` and `stderr` output is discarded.

Two things reach outside your program and are **off by default**, so a program that comes from outside your code
cannot read the host:

- `$ENV` and `env` are empty objects unless you pass `allowEnv: true`.
- `import` and `include` are compile errors (`JqCompileException`) unless you pass `allowModules: true`, which
  then searches where the program's `search` metadata says, else `~/.jq` and `$ORIGIN/../lib`.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Jq\Runtime\JqCompileException;

putenv('GREETING=hello');

echo json_encode(Jq::run('env.GREETING', null)), "\n";
echo json_encode(Jq::run('env.GREETING', null, allowEnv: true)), "\n";

try {
    Jq::run('import "composer" as $c {search: "."}; $c', null);
} catch (JqCompileException $error) {
    echo $error->getMessage(), "\n";
}
```

```text
[null]
["hello"]
modules are disabled: import and include are not available in this mode
```

## YAML and yq

`Yq::evaluate()` takes a yq expression and a document string and returns the text the `yq` command would print.
Comments and styles that the expression leaves alone are kept. The result can be YAML (the default), JSON or
any other format yq writes (`FormatEnum::Json`, `Csv`, `Props`, `Xml`, `Toml` and the rest).

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Yq;

$yaml = "# service settings\nitems:\n  - name: a\n    n: 1\n  - name: b\n    n: 2\n";

echo Yq::evaluate('.items[] | select(.n > 1) | .name', $yaml);
echo "--\n";
echo Yq::evaluate('.items[0].n = 10', $yaml);
echo "--\n";
echo Yq::evaluate('.items[1]', $yaml, output: FormatEnum::Json, indent: 0);
echo "--\n";
echo Yq::evaluate('.', '{"a": [1, 2]}', input: FormatEnum::Json);
echo "--\n";
echo Yq::evaluate('.', "a: 1\n---\nb: 2\n");
```

```text
b
--
# service settings
items:
  - name: a
    n: 10
  - name: b
    n: 2
--
{"name":"b","n":2}
--
a:
  - 1
  - 2
--
a: 1
---
b: 2
```

A multi-document stream is evaluated one document at a time, as `yq eval` does. The `env` and file operators
(`env()`, `load()` and friends) are refused unless you pass `allowEnv: true` or `allowFiles: true`; the
`system` operator is never available. Leave them off when the expression comes from outside your program.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Yq;

putenv('GREETING=hello');

try {
    Yq::evaluate('env("GREETING")', 'a: 1');
} catch (EvaluationException $error) {
    echo $error->getMessage(), "\n";
}

echo Yq::evaluate('env("GREETING")', 'a: 1', allowEnv: true);
```

```text
Environment variable operations have been disabled
hello
```

### YAML to PHP values, and jq over YAML

There is no YAML-to-array function. Convert to JSON with `Yq::evaluate()` and decode that, which also lets a jq
program run over a YAML document.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Yq;

$yaml = "items:\n  - name: a\n    n: 1\n  - name: b\n    n: 2\n";

$value = new JsonDecoder()->decodeOne(Yq::evaluate('.', $yaml, output: FormatEnum::Json));
echo implode(',', Jq::run('[.items[].name]', $value)[0]), "\n";
```

```text
a,b
```

## Errors

Every failure is an exception; nothing is written to standard error and the process never exits.

| Raised by      | Exception                                           | Meaning                                                              |
| -------------- | --------------------------------------------------- | -------------------------------------------------------------------- |
| `JsonDecoder`  | `LTS\PhpXq\Json\JsonSyntaxException`                | The text is not valid JSON, or is nested too deeply                  |
| `Jq::run`      | `LTS\PhpXq\Jq\Runtime\JqCompileException`           | The program does not parse or compile                                |
| `Jq::run`      | `LTS\PhpXq\Jq\Runtime\JqException`                  | The program raised an error; `->value` holds the jq value            |
| `Yq::evaluate` | `LTS\PhpXq\Yaml\Exception\YamlSyntaxException`      | The YAML input is malformed                                          |
| `Yq::evaluate` | `LTS\PhpXq\Yq\Format\FormatException`               | Other input is malformed, or a result does not fit the output format |
| `Yq::evaluate` | `LTS\PhpXq\Yq\Expression\ExpressionSyntaxException` | The expression does not parse                                        |
| `Yq::evaluate` | `LTS\PhpXq\Yq\Runtime\EvaluationException`          | The expression failed on the document                                |

All of them extend `RuntimeException`. These are the only exceptions the calls raise on bad input: `Yq::evaluate`
turns any internal command-line error into a `FormatException`, with the original as `getPrevious()`. A refused
`env` or file operator is an `EvaluationException`, and a refused `import` is a `JqCompileException`.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yq\Yq;

try {
    Jq::run('.a |', null);
} catch (JqCompileException) {
    echo "compile error\n";
}

try {
    Jq::run('error({code: 7})', null);
} catch (JqException $error) {
    echo 'jq error ', $error->value->get('code'), "\n";
}

try {
    Yq::evaluate('.', "a: [1\n");
} catch (YamlSyntaxException $error) {
    echo $error->getMessage(), "\n";
}
```

```text
compile error
jq error 7
yaml: line 2: did not find expected ',' or ']'
```

## Memory and limits

- The whole input and every output are held in memory; there is no streaming interface. Size `memory_limit` for
  the document plus its decoded form.
- Nesting is capped: JSON and YAML input deeper than 10,000 levels is rejected, and a jq program or yq
  expression is limited by `LTS\PhpXq\Limits\NestingLimit` (10,000 levels of brackets and operands, 100,000 for
  the whole tree). Deep evaluation runs on a dedicated stack, so these limits cannot overflow PHP's own.
- `LTS\PhpXq\Limits\AllocationLimit` bounds what a program can allocate in one step: an array index above
  536,870,911, more than 268,435,456 padding entries, or a string over 1 GiB is an error rather than an
  out-of-memory crash.
- A YAML document whose aliases expand to a huge tree (a "billion laughs" document) is rejected with
  `document contains excessive aliasing`.
- A jq program such as `repeat(1)` or `range(1e12)` can still run for as long as it likes. Set a
  `max_execution_time` or run untrusted programs in a worker you can kill.

## Stability

The classes in the [Public API](#public-api) table are supported: their names, method signatures and documented
behaviour change only in a release whose version number says so, under Semantic Versioning (while the major is 0,
a breaking change raises the minor). Everything else is internal and may change in any release, including a
patch release. If you need something that only an internal class offers, open an issue asking for it to be
promoted to the public API.
