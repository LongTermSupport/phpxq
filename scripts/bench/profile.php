<?php

declare(strict_types=1);

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Tests\Support\Bench\SamplingProfiler;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Sampling profiler for the jq benchmark workloads, no Xdebug needed: a forked child signals this process every
 * 500 microseconds and the handler records `debug_backtrace()`. Needs the pcntl and posix extensions.
 *
 * Usage: php scripts/bench/profile.php <jq filter> <input file> [repetitions] [rows]
 * Prints the functions with the most samples as their own frame (SELF) and anywhere on the stack (INCLUSIVE).
 */
if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
    fwrite(STDERR, "profile.php needs the pcntl and posix extensions\n");

    exit(2);
}

/** @var list<string> $argv */
$filter      = $argv[1] ?? '.';
$file        = $argv[2] ?? '';
$repetitions = (int)($argv[3] ?? 5);
$rows        = (int)($argv[4] ?? 25);
if ('' === $file || !is_file($file)) {
    fwrite(STDERR, "usage: php scripts/bench/profile.php <jq filter> <input file> [repetitions] [rows]\n");

    exit(2);
}

$profiler = new SamplingProfiler();
pcntl_async_signals(true);
pcntl_signal(\SIGUSR1, static function () use ($profiler): void {
    /** @var list<array{class?: string, type?: string, function: string, file?: string}> $frames */
    $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 60);
    // the first frame is this handler, called from where the process was interrupted
    array_shift($frames);
    $profiler->record($frames);
});

$parent  = (int)getmypid();
$sampler = pcntl_fork();
if (0 === $sampler) {
    while (posix_kill($parent, \SIGUSR1)) {
        usleep(500);
    }

    exit(0);
}

for ($run = 0; $run < $repetitions; ++$run) {
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    $stdin  = fopen('php://memory', 'r');
    if (false === $stdout || false === $stderr || false === $stdin) {
        fwrite(STDERR, "cannot open memory streams\n");

        exit(2);
    }

    JqApplication::create()->run([$filter, $file], $stdin, $stdout, $stderr);
}

pcntl_async_signals(false);
posix_kill($sampler, \SIGKILL);
pcntl_waitpid($sampler, $status);

echo $profiler->report($rows);
