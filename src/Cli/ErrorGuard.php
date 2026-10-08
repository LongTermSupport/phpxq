<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use ErrorException;

/**
 * Keeps PHP's own diagnostics out of the tools' output: warnings, notices and deprecations become
 * exceptions that the front controller reports in the tool's style, and a fatal error (such as running
 * out of memory) is reported as one line on standard error instead of PHP's banner.
 *
 * @api
 */
final readonly class ErrorGuard
{
    private const int FATAL = \E_ERROR | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR | \E_PARSE;

    private function __construct()
    {
    }

    /**
     * Install for the whole process; call once from the entry script.
     *
     * @param resource $stderr
     */
    public static function install(string $program, mixed $stderr): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        set_error_handler(self::raise(...));
        register_shutdown_function(static function () use ($program, $stderr): void {
            $message = self::describeFatal(error_get_last());
            if (null === $message) {
                return;
            }

            fwrite($stderr, $program . ': error: ' . $message . "\n");
            exit(FrontControllerInterface::EXIT_INTERNAL);
        });
    }

    /**
     * The error handler: false hands an error the `@` operator silenced back to PHP.
     *
     * @throws ErrorException
     */
    public static function raise(int $severity, string $message, string $file, int $line): bool
    {
        if (0 === (error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * @param ?array{type: int, message: string, file: string, line: int, ...} $error as from error_get_last()
     */
    public static function describeFatal(?array $error): ?string
    {
        if (null === $error || 0 === ($error['type'] & self::FATAL)) {
            return null;
        }

        return explode("\n", $error['message'])[0];
    }
}
