<?php

declare(strict_types=1);

/**
 * Decides how much of src/ the mutation run must cover for a change (scripts/Qa/MutationScope.php has the rules)
 * and, with --write, confines php-qa-ci's Infection lane to it. Run it through scripts/mutation-scope.bash, which
 * feeds it `git diff -z --name-status -M <base>...HEAD` on standard input. Output it cannot parse scopes everything.
 *
 * Prints `scope=none|files|all` (also to $GITHUB_OUTPUT when set) and the in-scope files. With --write:
 * - files: writes qaConfig/infection.json, php-qa-ci's project override, built by scripts/Qa/ScopedInfectionConfig.php
 *   from the generic Infection config. The file is gitignored and only ever generated.
 * - all, none: removes any such file, so the full run, or no run (the workflow then disables the lane), uses the
 *   shipped config.
 * Either way a failed write or removal exits 1. With --verify it changes nothing and exits 1 unless the file is
 * exactly what --write would leave for the scope (scripts/check-qa-measurements.bash relies on this).
 */

use LTS\PhpXq\Qa\MutationScope;
use LTS\PhpXq\Qa\ScopedInfectionConfig;
use LTS\PhpXq\Qa\ScopeKindEnum;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$rawArguments = $_SERVER['argv'] ?? [];
$arguments    = is_array($rawArguments) ? array_values(array_filter(array_slice($rawArguments, 1), is_string(...))) : [];
$write        = in_array('--write', $arguments, true);
$verify       = in_array('--verify', $arguments, true);

$diff = stream_get_contents(STDIN);
if (false === $diff) {
    fwrite(STDERR, "mutation-scope: could not read the diff from standard input\n");

    exit(1);
}

$sources  = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
        $sources[] = substr($file->getPathname(), strlen($root) + 1);
    }
}

$scope = new MutationScope($sources)->resolveNameStatusZ($diff);

echo 'scope=', $scope->kind->value, "\n";
echo 'files=', count($scope->files), ' of ', count($sources), "\n";
if (ScopeKindEnum::Files === $scope->kind) {
    foreach ($scope->files as $file) {
        echo '  ', $file, "\n";
    }
}

$githubOutput = getenv('GITHUB_OUTPUT');
if (is_string($githubOutput) && '' !== $githubOutput) {
    file_put_contents($githubOutput, 'scope=' . $scope->kind->value . "\n", FILE_APPEND);
}

if (!$write && !$verify) {
    exit(0);
}

$override = $root . '/qaConfig/infection.json';
$expected = null;
if (ScopeKindEnum::Files === $scope->kind) {
    $genericDir  = $root . '/vendor/lts/php-qa-ci/configDefaults/generic';
    $genericJson = file_get_contents($genericDir . '/infection.json');
    if (false === $genericJson) {
        fwrite(STDERR, "mutation-scope: could not read the generic Infection config\n");

        exit(1);
    }

    $config   = new ScopedInfectionConfig()->build(json_decode($genericJson, true, 512, JSON_THROW_ON_ERROR), $genericDir, $scope);
    $expected = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

if ($verify) {
    // The override must be exactly what --write produces for this scope: absent unless the scope is files.
    $actual = is_file($override) ? file_get_contents($override) : null;
    if ($actual !== $expected) {
        fwrite(STDERR, null === $expected
            ? "mutation-scope: qaConfig/infection.json is present but the scope is {$scope->kind->value}, so the run did not measure all of it\n"
            : "mutation-scope: qaConfig/infection.json is missing or does not match the scope, so the run did not measure what it had to\n");

        exit(1);
    }

    echo "verified qaConfig/infection.json against the scope\n";

    exit(0);
}

if (is_file($override) && !unlink($override)) {
    fwrite(STDERR, "mutation-scope: could not remove qaConfig/infection.json\n");

    exit(1);
}

if (null === $expected) {
    exit(0);
}

if (false === file_put_contents($override, $expected)) {
    fwrite(STDERR, "mutation-scope: could not write qaConfig/infection.json, so Infection would mutate all of src/\n");

    exit(1);
}

echo 'wrote qaConfig/infection.json (', count($scope->excludes()), " files excluded)\n";
