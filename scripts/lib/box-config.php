<?php

declare(strict_types=1);

/**
 * Derives the build-time Box config from the committed box.json.
 *
 * Usage: php box-config.php <box.json> <output.phar> <epoch> <target box.json>
 *
 * Pins the PHAR timestamp to the given Unix epoch so repeated builds are byte-identical.
 */
$rawArguments = $_SERVER['argv'];
$arguments    = [];
foreach (\is_array($rawArguments) ? $rawArguments : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

if (5 !== \count($arguments)) {
    fwrite(STDERR, "usage: box-config.php <box.json> <output.phar> <epoch> <target>\n");

    exit(2);
}

[, $source, $outputPhar, $epoch, $target] = $arguments;

$config = json_decode((string)file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
if (!\is_array($config)) {
    fwrite(STDERR, "box-config.php: {$source} does not hold a JSON object\n");

    exit(2);
}

$config['output']    = $outputPhar;
$config['timestamp'] = gmdate('Y-m-d H:i:s', (int)$epoch);

file_put_contents($target, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
