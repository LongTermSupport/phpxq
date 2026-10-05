<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

use LTS\PhpXq\Tests\Support\CliResult;
use RuntimeException;

/**
 * Runs bin/phpxq yq as a real child process under a fixed native stack limit, so a defect that overflows
 * the C stack shows up as a signal exit status (139 for a segmentation fault) instead of killing the test
 * runner. The shell reports the child's own status, which the process handle of the runner would lose.
 */
final readonly class YqProcess
{
    /** The default stack limit of most Linux shells, in kilobytes: what an ordinary user's process gets. */
    public const int DEFAULT_STACK_KILOBYTES = 8192;

    /** Value of XDEBUG_MODE that makes Xdebug track coverage, which costs extra native stack per PHP call. */
    public const string XDEBUG_COVERAGE = 'coverage';

    /** Value of XDEBUG_MODE that switches Xdebug off for the child. */
    public const string XDEBUG_OFF = 'off';

    /**
     * @param list<string> $arguments yq arguments, without the program name
     */
    public static function run(array $arguments, string $stdin, string $xdebugMode, int $stackKilobytes = self::DEFAULT_STACK_KILOBYTES): CliResult
    {
        $directory = sys_get_temp_dir() . '/phpxq-process-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('could not create ' . $directory);
        }

        $stdinPath  = $directory . '/stdin';
        $stdoutPath = $directory . '/stdout';
        $stderrPath = $directory . '/stderr';
        $statusPath = $directory . '/status';
        file_put_contents($stdinPath, $stdin);

        $quoted = implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/phpxq', 'yq', ...$arguments]));
        $script = \sprintf(
            'ulimit -s %d; XDEBUG_MODE=%s %s <%s >%s 2>%s; echo $? >%s',
            $stackKilobytes,
            escapeshellarg($xdebugMode),
            $quoted,
            escapeshellarg($stdinPath),
            escapeshellarg($stdoutPath),
            escapeshellarg($stderrPath),
            escapeshellarg($statusPath),
        );

        $handle = popen('sh -c ' . escapeshellarg($script), 'r');
        if (false === $handle) {
            throw new RuntimeException('could not start the yq child process');
        }

        pclose($handle);

        $result = new CliResult(
            (int)file_get_contents($statusPath),
            (string)file_get_contents($stdoutPath),
            (string)file_get_contents($stderrPath),
        );
        array_map(unlink(...), [$stdinPath, $stdoutPath, $stderrPath, $statusPath]);
        rmdir($directory);

        return $result;
    }
}
