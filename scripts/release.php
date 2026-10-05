#!/usr/bin/env php
<?php

declare(strict_types=1);

use LTS\PhpXq\Release\ReleaseCommand;

require \dirname(__DIR__) . '/vendor/autoload.php';

$root = getcwd();
if (false === $root) {
    fwrite(\STDERR, "release.php: cannot read the working directory; run it from the project root.\n");
    exit(ReleaseCommand::EXIT_REFUSED);
}

$rawArguments = $_SERVER['argv'];
$arguments    = [];
foreach (\array_slice(\is_array($rawArguments) ? $rawArguments : [], 1) as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

$result = new ReleaseCommand($root)->run(...$arguments);
fwrite(\STDOUT, $result->stdout);
fwrite(\STDERR, $result->stderr);
exit($result->exitCode);
