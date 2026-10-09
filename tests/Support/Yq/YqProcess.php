<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

use LTS\PhpXq\Cli\Xdebug;
use LTS\PhpXq\Tests\Support\CliResult;
use Symfony\Component\Process\Process;

/**
 * Runs bin/phpxq yq as a real child process under a fixed native stack limit, so a defect that overflows
 * the C stack shows up as a signal exit status (139 for a segmentation fault) instead of killing the test
 * runner. The shell runs the child without exec, so it reports the child's 128 + signal status itself.
 */
final readonly class YqProcess
{
    /** Longest a single child may run before the test fails. */
    public const int TIMEOUT_SECONDS = 600;

    /** The default stack limit of most Linux shells, in kilobytes: what an ordinary user's process gets. */
    public const int DEFAULT_STACK_KILOBYTES = 8192;

    /** Value of XDEBUG_MODE that makes Xdebug track coverage, which costs extra native stack per PHP call. */
    public const string XDEBUG_COVERAGE = 'coverage';

    /** Value of XDEBUG_MODE that switches Xdebug off for the child. */
    public const string XDEBUG_OFF = 'off';

    private function __construct()
    {
    }

    /**
     * @param list<string> $arguments yq arguments, without the program name
     */
    public static function run(array $arguments, string $stdin, string $xdebugMode, int $stackKilobytes = self::DEFAULT_STACK_KILOBYTES): CliResult
    {
        $process = new Process(
            ['sh', '-c', 'ulimit -s "$1" && shift && "$@"', 'sh', (string)$stackKilobytes, \PHP_BINARY, \dirname(__DIR__, 3) . '/bin/phpxq', 'yq', ...$arguments],
            null,
            ['XDEBUG_MODE' => $xdebugMode, Xdebug::ALLOW_ENVIRONMENT_VARIABLE => '1'],
            $stdin,
            self::TIMEOUT_SECONDS,
        );
        $process->run();

        return new CliResult((int)$process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
