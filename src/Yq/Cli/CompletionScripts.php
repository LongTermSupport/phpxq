<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The shell completion scripts printed by `yq completion <shell>`. Every script asks the program itself
 * (`yq __complete ...`, see {@see CompleteCommand}) for candidates, as cobra's generated scripts do.
 *
 * @internal
 */
final readonly class CompletionScripts
{
    public const array SHELLS = [ShellEnum::Bash->value, ShellEnum::Zsh->value, ShellEnum::Fish->value, ShellEnum::Powershell->value];

    private const string BASH = <<<'SCRIPT'
        # bash completion for yq                                   -*- shell-script -*-

        __yq_get_completion_results() {
            local out
            out=$("${COMP_WORDS[0]}" __complete "${COMP_WORDS[@]:1:COMP_CWORD}" 2>/dev/null)
            __yq_directive="${out##*$'\n'}"
            __yq_directive="${__yq_directive#:}"
            if [[ "$out" == *$'\n'* ]]; then
                __yq_out="${out%$'\n'*}"
            else
                __yq_out=""
            fi
        }

        __start_yq() {
            local cur words cword
            cur="${COMP_WORDS[COMP_CWORD]}"
            COMPREPLY=()
            __yq_get_completion_results
            local line
            while IFS= read -r line; do
                if [[ -n "$line" ]]; then
                    COMPREPLY+=("${line%%$'\t'*}")
                fi
            done <<<"$__yq_out"
            if (( (__yq_directive & 4) == 0 )) && (( ${#COMPREPLY[@]} == 0 )); then
                compopt -o default 2>/dev/null
            fi
            if (( (__yq_directive & 2) != 0 )); then
                compopt -o nospace 2>/dev/null
            fi
        }

        if [[ $(type -t compopt) = "builtin" ]]; then
            complete -o default -F __start_yq yq
        else
            complete -o default -o nospace -F __start_yq yq
        fi

        # ex: ts=4 sw=4 et filetype=sh

        SCRIPT;

    private const string ZSH = <<<'SCRIPT'
        #compdef yq
        compdef _yq yq

        # zsh completion for yq                                   -*- shell-script -*-

        _yq() {
            local -a lines completions
            local out directive
            out=$(${words[1]} __complete "${(@)words[2,CURRENT]}" 2>/dev/null)
            lines=("${(@f)out}")
            directive="${lines[-1]#:}"
            lines=("${(@)lines[1,-2]}")
            for line in "${lines[@]}"; do
                [[ -n "$line" ]] && completions+=("${line/$'\t'/:}")
            done
            if (( ${#completions[@]} > 0 )); then
                _describe 'completions' completions
            elif (( (directive & 4) == 0 )); then
                _files
            fi
        }

        # don't run the completion function when being source-ed or eval-ed
        if [ "$funcstack[1]" = "_yq" ]; then
            _yq
        fi

        SCRIPT;

    private const string FISH = <<<'SCRIPT'
        # fish completion for yq                                   -*- shell-script -*-

        function __yq_perform_completion
            set -l args (commandline -opc)
            set -l lastArg (commandline -ct)
            set -l results ($args[1] __complete $args[2..-1] $lastArg 2>/dev/null)
            set -l directive (string sub --start 2 $results[-1])
            set -e results[-1]
            printf '%s\n' $results
            if test (math "$directive" % 8) -lt 4
                __fish_complete_path $lastArg
            end
        end

        complete -c yq -e
        complete -c yq -n '__fish_seen_subcommand_from' -f
        complete -c yq -a '(__yq_perform_completion)'

        SCRIPT;

    private const string POWERSHELL = <<<'SCRIPT'
        # powershell completion for yq                                   -*- shell-script -*-

        Register-ArgumentCompleter -CommandName 'yq' -ScriptBlock {
            param($wordToComplete, $commandAst, $cursorPosition)

            $elements = $commandAst.CommandElements
            $arguments = @($elements | Select-Object -Skip 1 | ForEach-Object { $_.ToString() })
            if ($wordToComplete -eq '') { $arguments += '""' }

            $program = $elements[0].ToString()
            $out = & $program __complete @arguments 2>$null
            $lines = @($out -split "`n" | Where-Object { $_ -ne '' })
            if ($lines.Count -eq 0) { return }
            $lines = $lines | Select-Object -SkipLast 1

            foreach ($line in $lines) {
                $parts = $line -split "`t", 2
                $description = if ($parts.Count -gt 1) { $parts[1] } else { $parts[0] }
                [System.Management.Automation.CompletionResult]::new($parts[0], $parts[0], 'ParameterValue', $description)
            }
        }

        SCRIPT;

    private function __construct()
    {
    }

    public static function forShell(string $shell): string
    {
        return match (ShellEnum::tryFrom($shell)) {
            ShellEnum::Bash       => self::BASH,
            ShellEnum::Zsh        => self::ZSH,
            ShellEnum::Fish       => self::FISH,
            ShellEnum::Powershell => self::POWERSHELL,
            default               => '',
        };
    }
}
