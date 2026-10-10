<?php

declare(strict_types=1);

/**
 * Prints the comma-separated PHP extension list for the static runtime.
 *
 * Usage: php extensions.php <packaging/extensions.txt> <composer.json>
 *
 * The list is every extension named in packaging/extensions.txt plus every `ext-*` the composer.json
 * `require` section declares, de-duplicated and sorted, so declaring an extension in composer.json is
 * enough to get it compiled in.
 */
$rawArguments = $_SERVER['argv'];
$arguments    = [];
foreach (\is_array($rawArguments) ? $rawArguments : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

if (3 !== \count($arguments)) {
    fwrite(STDERR, "Usage: extensions.php <extensions.txt> <composer.json>\n");

    exit(2);
}

[, $listFile, $composerFile] = $arguments;

$lines = file($listFile, FILE_IGNORE_NEW_LINES);
if (false === $lines) {
    fwrite(STDERR, "extensions.php: cannot read {$listFile}\n");

    exit(2);
}

$extensions = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ('' === $line || str_starts_with($line, '#')) {
        continue;
    }

    $extensions[] = $line;
}

$composer = json_decode((string)file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
$require  = \is_array($composer) && \is_array($composer['require'] ?? null) ? $composer['require'] : [];
foreach (array_keys($require) as $package) {
    if (str_starts_with((string)$package, 'ext-')) {
        $extensions[] = substr((string)$package, 4);
    }
}

$extensions = array_values(array_unique($extensions));
sort($extensions);

echo implode(',', $extensions), "\n";
