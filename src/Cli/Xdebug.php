<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

/**
 * Whether Xdebug is switched on for this process. A loaded extension whose mode is "off" costs next to nothing,
 * so only a loaded extension with a real mode (coverage, develop, debug, ...) counts.
 *
 * @internal
 */
final readonly class Xdebug
{
    /** Setting this variable to a non-empty value other than "0" keeps Xdebug on. */
    public const string ALLOW_ENVIRONMENT_VARIABLE = 'PHPXQ_ALLOW_XDEBUG';

    /** The Xdebug mode that switches every feature off. */
    private const string MODE_OFF = 'off';

    /** The environment variable Xdebug reads its mode from before the ini setting. */
    private const string MODE_ENVIRONMENT_VARIABLE = 'XDEBUG_MODE';

    /** Looked up by name: pcntl is optional (the static binary may lack it), so nothing may require it. */
    private const string REPLACE_PROCESS_FUNCTION = 'pcntl_exec';

    private function __construct()
    {
    }

    /**
     * Replaces this process with the same script run with Xdebug switched off, because Xdebug slows every call
     * and uses a lot more native stack. Returns when no restart is wanted or possible.
     *
     * @param string ...$arguments the script path and its arguments, as in $argv
     */
    public static function restartWithoutIfActive(string ...$arguments): void
    {
        $allowed = getenv(self::ALLOW_ENVIRONMENT_VARIABLE);
        if (!self::shouldRestart(self::active(), \is_string($allowed) ? $allowed : '', \extension_loaded('pcntl'))) {
            return;
        }

        \call_user_func(self::REPLACE_PROCESS_FUNCTION, PHP_BINARY, $arguments, self::restartEnvironment(getenv()));
    }

    public static function shouldRestart(bool $active, string $allowed, bool $canReplaceProcess): bool
    {
        return $active && $canReplaceProcess && ('' === $allowed || '0' === $allowed);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public static function restartEnvironment(array $environment): array
    {
        $environment[self::MODE_ENVIRONMENT_VARIABLE] = self::MODE_OFF;

        return $environment;
    }

    public static function active(): bool
    {
        $fromEnvironment = getenv(self::MODE_ENVIRONMENT_VARIABLE);
        $mode            = \is_string($fromEnvironment) && '' !== $fromEnvironment ? $fromEnvironment : (string)\ini_get('xdebug.mode');

        return self::isActive(\extension_loaded('xdebug'), $mode);
    }

    public static function isActive(bool $loaded, string $mode): bool
    {
        if (!$loaded) {
            return false;
        }

        $mode = strtolower(trim($mode));

        return '' !== $mode && self::MODE_OFF !== $mode;
    }
}
