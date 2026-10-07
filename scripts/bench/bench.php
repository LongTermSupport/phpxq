<?php

declare(strict_types=1);

use LTS\PhpXq\Tests\Support\Bench\BenchCli;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
exit(new BenchCli()->run(STDOUT, STDERR, ...\array_slice($argv, 1)));
