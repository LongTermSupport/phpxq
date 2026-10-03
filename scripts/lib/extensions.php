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
if ($argc !== 3) {
    fwrite(STDERR, "usage: extensions.php <extensions.txt> <composer.json>\n");

    exit(2);
}

[, $listFile, $composerFile] = $argv;

$extensions = [];
foreach (file($listFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ('' === $line || str_starts_with($line, '#')) {
        continue;
    }
    $extensions[] = $line;
}

$composer = json_decode((string)file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($composer['require'] ?? []) as $package) {
    if (str_starts_with((string)$package, 'ext-')) {
        $extensions[] = substr((string)$package, 4);
    }
}

$extensions = array_values(array_unique($extensions));
sort($extensions);

echo implode(',', $extensions), "\n";
