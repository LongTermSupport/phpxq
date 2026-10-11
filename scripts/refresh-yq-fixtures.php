<?php

declare(strict_types=1);

/**
 * Regenerates tests/Conformance/Yq/fixtures/cases.json and skipped.json from a mikefarah/yq clone.
 *
 * Usage: php scripts/refresh-yq-fixtures.php <path-to-yq-clone>
 *
 * Check out the pinned tag first (see tests/Conformance/Yq/fixtures/NOTICE.md).
 */

use LTS\PhpXq\Tests\Support\Yq\YqFixtureGenerator;

require __DIR__ . '/../vendor/autoload.php';

$clone = $argv[1] ?? null;
if (null === $clone || !is_dir($clone)) {
    fwrite(\STDERR, "Usage: php scripts/refresh-yq-fixtures.php <path-to-yq-clone>\n");

    exit(2);
}

$extraction = new YqFixtureGenerator()->generate($clone);
$target     = __DIR__ . '/../tests/Conformance/Yq/fixtures';

if (!is_dir($target) && !mkdir($target, 0o777, true)) {
    fwrite(\STDERR, "Could not create {$target}\n");

    exit(1);
}

file_put_contents($target . '/cases.json', $extraction->casesJson());
file_put_contents($target . '/skipped.json', $extraction->skippedJson());

fwrite(\STDOUT, \sprintf("%d cases, %d skipped\n", \count($extraction->cases), \count($extraction->skipped)));
