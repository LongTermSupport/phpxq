<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/*
 * Project copy of vendor/lts/php-qa-ci/configDefaults/generic/rector-php85.php. The sets and skips are the
 * shipped ones; the only change is the parallel job timeout. On this code base a job of sixteen files
 * regularly needs more than the shipped 120 seconds (Rector's type resolution is slow here, the unmodified
 * main branch hits the same "Child process timed out" error), and a timeout aborts the whole lane.
 */
return static function (RectorConfig $rectorConfig): void {
    $cpuThreads   = substr_count((string)file_get_contents('/proc/cpuinfo'), "processor\t:") ?: 4;
    $maxProcesses = max(1, (int)floor($cpuThreads / 2));

    $rectorConfig->parallel(
        900,
        $maxProcesses,
        16,
    );

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_85,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
    ]);

    // The two skips are explained in the shipped config: both rules manufacture code the PHPStan level-max
    // configuration and the opt-in `?? ''` rule then reject.
    $rectorConfig->skip([
        Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
        Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector::class,
    ]);

    if (isset($_SERVER['rectorIgnorePaths'])) {
        $ignorePaths = array_filter(array_map('trim', explode("\n", $_SERVER['rectorIgnorePaths'])));
        $rectorConfig->skip($ignorePaths);
    }
};
