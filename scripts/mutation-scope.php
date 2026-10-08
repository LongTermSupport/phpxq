<?php

declare(strict_types=1);

/**
 * Decides how much of src/ the mutation run must cover for a change (scripts/Qa/MutationScope.php has the rules)
 * and, with --write, confines php-qa-ci's Infection lane to it. Run it through scripts/mutation-scope.bash, which
 * feeds it `git diff --name-status -M <base>...HEAD` on standard input.
 *
 * Prints `scope=none|files|all` (also to $GITHUB_OUTPUT when set) and the in-scope files. With --write:
 * - files: writes qaConfig/infection.json, php-qa-ci's project override, as its generic Infection config with every
 *   out-of-scope source file excluded. The file is gitignored and only ever generated.
 * - all, none: removes any such file, so the full run, or no run (the workflow then disables the lane), uses the
 *   shipped config.
 */

use LTS\PhpXq\Qa\FileChange;
use LTS\PhpXq\Qa\MutationScope;
use LTS\PhpXq\Qa\ScopeKindEnum;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$write = in_array('--write', array_slice($argv, 1), true);
$diff  = (string)stream_get_contents(STDIN);

$sources  = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
        $sources[] = substr($file->getPathname(), strlen($root) + 1);
    }
}

$scope = new MutationScope($sources)->resolve(...FileChange::parseNameStatus($diff));

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

if (!$write) {
    exit(0);
}

$override = $root . '/qaConfig/infection.json';
if (is_file($override)) {
    unlink($override);
}

if (ScopeKindEnum::Files !== $scope->kind) {
    exit(0);
}

$genericDir = $root . '/vendor/lts/php-qa-ci/configDefaults/generic';
$config     = json_decode((string)file_get_contents($genericDir . '/infection.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($config)) {
    fwrite(STDERR, "mutation-scope: the generic Infection config is not a JSON object\n");

    exit(1);
}

// The generic config's paths are relative to its own directory; the override lives in qaConfig/, so make them
// absolute. Infection's PHPUnit config directory stays the generic one, exactly as in an unscoped run.
$absolute = static fn (string $path): string => normalise(str_starts_with($path, '/') ? $path : $genericDir . '/' . $path);
$config['source']['directories'] = array_map($absolute, $config['source']['directories']);
$config['source']['excludes']    = $scope->excludes();
foreach ($config['logs'] as $name => $path) {
    $config['logs'][$name] = $absolute($path);
}
$config['phpUnit']['configDir'] = $absolute($config['phpUnit']['configDir']);
$config['tmpDir']               = $absolute($config['tmpDir']);

file_put_contents($override, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'wrote qaConfig/infection.json (', count($config['source']['excludes']), " files excluded)\n";

function normalise(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ('..' === $part) {
            array_pop($parts);
        } elseif ('' !== $part && '.' !== $part) {
            $parts[] = $part;
        }
    }

    return '/' . implode('/', $parts) . (str_ends_with($path, '/') ? '/' : '');
}
