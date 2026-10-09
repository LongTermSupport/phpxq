<?php

declare(strict_types=1);

namespace LTS\PhpXq\Scripts;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Tests\Support\Bench\SamplingProfiler;
use LTS\PhpXq\Yq\Cli\YqApplication;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Sampling profiler for the jq benchmark workloads, no Xdebug needed: a forked child signals this process every
 * 500 microseconds and the handler records `debug_backtrace()`. Needs the pcntl and posix extensions.
 *
 * A `.yaml` or `.yml` input file profiles `yq` (the filter is a yq expression), anything else profiles `jq`.
 *
 * Usage: php scripts/bench/profile.php <filter> <input file> [repetitions] [rows]
 * Prints the functions with the most samples as their own frame (SELF) and anywhere on the stack (INCLUSIVE).
 */
if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
    fwrite(STDERR, "profile.php needs the pcntl and posix extensions\n");

    exit(2);
}

$rawArguments = $_SERVER['argv'];
$arguments    = [];
foreach (\is_array($rawArguments) ? $rawArguments : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

$filter      = $arguments[1] ?? '.';
$file        = $arguments[2] ?? null;
$repetitions = (int)($arguments[3] ?? 5);
$rows        = (int)($arguments[4] ?? 25);
if (null === $file || '' === $file || !is_file($file)) {
    fwrite(STDERR, "usage: php scripts/bench/profile.php <jq filter> <input file> [repetitions] [rows]\n");

    exit(2);
}

$profiler = new SamplingProfiler();
pcntl_async_signals(true);
pcntl_signal(\SIGUSR1, static function () use ($profiler): void {
    $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 60);
    // the first frame is this handler, called from where the process was interrupted
    array_shift($frames);
    $profiler->record(...$frames);
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
    if (\in_array(false, [$stdout, $stderr, $stdin], true)) {
        fwrite(STDERR, "cannot open memory streams\n");

        exit(2);
    }

    if (str_ends_with($file, '.yaml') || str_ends_with($file, '.yml')) {
        new YqApplication()->run($stdin, $stdout, $stderr, $filter, $file);
    } else {
        JqApplication::create()->run($stdin, $stdout, $stderr, $filter, $file);
    }
}

pcntl_async_signals(false);
posix_kill($sampler, \SIGKILL);
pcntl_waitpid($sampler, $status);

echo $profiler->report($rows);
