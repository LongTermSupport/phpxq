<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * Everything a running program may reach outside its input value. The CLI builds one per invocation;
 * tests use a plain implementation. Natives receive it as their first argument.
 *
 * @api
 */
interface RuntimeContext
{
    public function inputs(): InputProviderInterface;

    /**
     * Predefined and `--arg` style variables by name without `$`: ENV, __prog_args, ARGS and the
     * named arguments. The same names are passed to {@see CompilerInterface::compile()}.
     *
     * @return array<string, mixed>
     */
    public function globals(): array;

    /**
     * `input_filename`: the file currently being read, null for stdin.
     */
    public function inputFilename(): ?string;

    /**
     * Library search path (`-L`), as returned by `get_search_list`.
     *
     * @return list<string>
     */
    public function libraryPaths(): array;

    /**
     * `debug` / `debug(msg)`: writes `["DEBUG:",value]` as compact JSON plus newline to stderr.
     */
    public function debug(mixed $value): void;

    /**
     * `stderr`: writes the value as compact JSON, no newline, to stderr (a string is written raw in 1.8).
     */
    public function writeStderr(mixed $value): void;

    /**
     * Seconds since the epoch with fractions (`now`), injectable for tests.
     */
    public function now(): float;
}
