<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\Program;

/**
 * `--debug-dump-disasm`: phpxq has no bytecode, so the dump lists what the program binds: a `TOP` line and
 * one `name/arity:` line for every top-level definition the main program reaches, directly or through
 * other definitions (a later definition replaces an earlier one of the same name and arity, and
 * definitions nothing refers to are not bound, as in jq).
 *
 * @api
 */
final class ProgramDump
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function lines(Program $program): array
    {
        $definitions = [];
        foreach ($program->defs as $definition) {
            $definitions[$definition->signature()] = $definition;
        }

        $pending = $program->body instanceof \LTS\PhpXq\Jq\Ast\NodeInterface ? self::calls($program->body) : [];
        $reached = [];
        while ([] !== $pending) {
            $signature = array_shift($pending);
            if (isset($reached[$signature]) || !isset($definitions[$signature])) {
                continue;
            }

            $reached[$signature] = true;
            array_push($pending, ...self::calls($definitions[$signature]->body));
        }

        return ['TOP', ...array_map(static fn (string $signature): string => $signature . ':', array_keys($reached))];
    }

    /**
     * The name/arity of every call below $value, which may be an AST node, any other AST object, or a list.
     *
     * @return list<string>
     */
    private static function calls(mixed $value): array
    {
        if (\is_array($value)) {
            $found = [];
            foreach ($value as $item) {
                array_push($found, ...self::calls($item));
            }

            return $found;
        }

        if (!\is_object($value)) {
            return [];
        }

        $found = $value instanceof FunctionCall ? [$value->signature()] : [];
        foreach (get_object_vars($value) as $member) {
            array_push($found, ...self::calls($member));
        }

        return $found;
    }
}
