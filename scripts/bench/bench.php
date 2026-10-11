<?php

declare(strict_types=1);

use LTS\PhpXq\Tests\Support\Bench\BenchCli;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$rawArguments = $_SERVER['argv'];
$arguments    = [];
foreach (\array_slice(\is_array($rawArguments) ? $rawArguments : [], 1) as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

exit(new BenchCli()->run(STDOUT, STDERR, ...$arguments));
