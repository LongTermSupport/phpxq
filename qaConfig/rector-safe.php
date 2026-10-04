<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Project override of php-qa-ci's rector-safe.php.
 *
 * The shipped config rewrites native calls to \Safe\* equivalents, which makes
 * thecodingmachine/safe a runtime dependency. This project carries NO
 * production dependencies (composer.json "require" is the PHP version only), so
 * no Safe conversion is applied: this lane runs with an empty rule set.
 */
return static function (RectorConfig $rectorConfig): void {
    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map(trim(...), explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
