<?php

declare(strict_types=1);

/**
 * Prints the PHP conformance report: every upstream case of each suite classified against the suite's
 * known-gaps.txt. Exits 0 when no suite has an unexpected failure or unexpected pass, else 1.
 *
 * Usage: php scripts/conformance-report.php [jq|yq|all]
 */

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceReport;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceSuiteInterface;
use LTS\PhpXq\Tests\Support\Conformance\GapList;
use LTS\PhpXq\Tests\Support\Conformance\JqConformanceSuite;
use LTS\PhpXq\Tests\Support\Conformance\YqConformanceSuite;

require __DIR__ . '/../vendor/autoload.php';

$which = $argv[1] ?? 'all';
if (!\in_array($which, ['jq', 'yq', 'all'], true)) {
    fwrite(\STDERR, "Usage: php scripts/conformance-report.php [jq|yq|all]\n");

    exit(2);
}

/** @var list<ConformanceSuiteInterface> $suites */
$suites = [];
if ('yq' !== $which) {
    $suites[] = new JqConformanceSuite();
}

if ('jq' !== $which) {
    $suites[] = new YqConformanceSuite();
}

$root       = \dirname(__DIR__);
$report     = new ConformanceReport();
$runner     = new CliRunner();
$problems   = false;
$totalCases = 0;
$totalOk    = 0;

foreach ($suites as $suite) {
    $result = $report->run($suite, $runner, GapList::fromFile($root . '/tests/Conformance/' . ucfirst($suite->name()) . '/known-gaps.txt'));

    foreach ($result->summaryLines() as $line) {
        fwrite(\STDOUT, $line . "\n");
    }

    $problems = $problems || $result->hasProblems();
    $totalCases += $result->total();
    $totalOk    += $result->passed + $result->expectedFailures;
}

fwrite(\STDOUT, \sprintf("TOTAL: %d cases | %d as expected | %s\n", $totalCases, $totalOk, $problems ? 'PROBLEMS FOUND' : 'OK'));

exit($problems ? 1 : 0);
