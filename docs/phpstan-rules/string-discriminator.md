# `phpxq.stringDiscriminator` - a closed set of names written as strings

**Rule**: `StringDiscriminatorRule` (`qaConfig/PHPStan/Rules/`, registered in `qaConfig/phpstan.neon` behind
`ProductionOnlyRule`, so it judges `src/` only)

## What fires

One finding per method and subject expression, when the same variable, property (`$this->mode`,
`$call->name`, `$a?->b`) or static property is compared with **two or more different word-like string
literals** in that method, through any mix of:

- `===`, `!==`, `==`, `!=` (either operand order);
- `in_array($subject, ['a', 'b'], ...)` (positional or named arguments);
- `match ($subject) { 'a' => ..., 'b' => ... }`;
- `switch ($subject) { case 'a': ... case 'b': ... }`.

A literal is word-like when it starts with a letter or `_` and holds letters, digits, `_` and `-`. The empty
string, punctuation (`'.'`, `','`, `'[]'`) and numeric strings are not names and are never counted. A subject
is a variable, a property of one, or a static property; calls, array elements and concatenations are not
subjects.

```php
if ('head' === $kind || 'line' === $kind) { ... }          // fires: $kind, 'head', 'line'
return match ($call->name) { 'sub' => ..., 'test' => ... }; // fires: $call->name
```

## Why it is a hazard

A kind, mode, format, tool or flag name that lives in a variable and is told apart with string literals is a
closed set with no single definition. Each comparison site restates part of the set; a misspelt literal is a
branch that silently never matches, a new member has to be added at every site by hand, and nothing says
which values are legal. `phpqaci.repeatedStringLiteral` sees the same literal written three times in a class;
it does not see `'head'`, `'line'` and `'foot'` each written once in different branches, which is the
discriminator shape.

## The correct construction

A backed enum, resolved once at the boundary and matched exhaustively afterwards:

```php
enum CommentKindEnum: string { case Head = 'head'; case Line = 'line'; case Foot = 'foot'; }

$kind = CommentKindEnum::tryFrom($text) ?? throw new UsageException(...);
return match ($kind) { CommentKindEnum::Head => ..., CommentKindEnum::Line => ..., CommentKindEnum::Foot => ... };
```

Where the values are not a type (a handful of data strings with no behaviour of their own), a class
constant per value gives each one a single definition. The enum name must end in `Enum` (PHPArkitect).

## What is not reported

- One literal per subject (`'head' === $a && 'foot' === $b`): that is a single comparison, not a set.
- Constants and enum cases on the other side (`self::HEAD === $kind`, `Kind::Head === $kind`).
- `match (true)`, calls (`strtolower($x)`), array elements and other non-subject expressions.
- An anonymous class is judged as a class of its own, not as part of the method that creates it.
- Anything in a namespace or class listed below.

## Syntax readers are the one allowed exception

The rule takes a list of namespace prefixes (`syntaxNamespaces` in `qaConfig/phpstan.neon`); a class whose
name starts with one of them is not judged. The list is not a baseline: it names code whose literals are the
**grammar of an external text syntax**, so the set is defined by a specification outside the project, has
exactly one comparison site per grammar production, and cannot drift from a second restatement of itself.
Each entry below carries its reason; an entry is added only with one.

| Prefix                                                                                        | Reason                                                                                                                |
| --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `LTS\PhpXq\Json`                                                                              | RFC 8259: the literals `true`, `false`, `null` and the escape letters of a JSON string.                               |
| `LTS\PhpXq\Yaml\Token`, `LTS\PhpXq\Yaml\Parser`, `LTS\PhpXq\Yaml\Schema`                      | YAML 1.2: directive names, escape letters and the core schema's spellings of null, booleans and numbers.              |
| `LTS\PhpXq\Jq\Parser`                                                                         | jq grammar: the keywords `true`, `false`, `null`, `break` and the like in the position the parser reads them.         |
| `LTS\PhpXq\Yq\Expression\Parser`                                                              | yq expression grammar: `and`, `or`, `as`, `ref`, `if`/`elif`/`else`, `reduce` and string escape letters.              |
| `LTS\PhpXq\Jq\Builtin\Regex`                                                                  | Oniguruma flags and escapes being translated to PCRE: the letters are Oniguruma's.                                    |
| `LTS\PhpXq\Jq\Builtin\Date\Strftime`, `LTS\PhpXq\Jq\Builtin\Date\Strptime`                    | The conversion specifier letters of C `strftime` and `strptime`.                                                      |
| Format readers in `LTS\PhpXq\Yq\Format\Codec` (named class by class in `phpstan.neon`)        | The escape letters, keywords and entity names of the TOML, HCL, Lua, XML, `.properties` and JSON text they read.      |
| `LTS\PhpXq\Yq\Runtime\GoTime`, `LTS\PhpXq\Yq\Runtime\GoRegex`, `LTS\PhpXq\Yq\Runtime\Numbers` | Go's reference-time layout tokens (`Jan`, `MST`, `-0700`), Go regexp flags, and the `Inf`/`NaN` spellings Go accepts. |

Dispatch on names the project itself defines (the call names an operator class answers to, a command, a
format, a shell, a comment kind) is the hazard the rule exists for and is never allowed by namespace.

## Limits

- One method at a time: the same subject compared with `'a'` in one method and `'b'` in another is two
  comparisons the rule does not join.
- A closure inside a method shares that method's scope, so a variable of the same name in the closure and in
  the method counts as one subject.
- Only word-like literals are names. A one-character flag compared with `===` is a name when it is a letter
  (`'c' === $modifiers`) and not when it is punctuation.
- A subject held in a local alias (`$k = $call->name;` then `'a' === $k`) is judged under the alias name.
