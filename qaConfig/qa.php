<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

/**
 * Project QA configuration (see vendor/lts/php-qa-ci/templates/qaConfig-qa.php).
 *
 * Related overrides in this directory:
 * - rector-safe.php: no Safe-function conversion (no production dependencies).
 * - composerRequireChecker.json: no thecodingmachine/safe scan files, for the same reason.
 */
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // A jq/yq equivalent is a data-processing CLI: it handles no passwords, tokens or other secrets.
    ->withSensitiveParameterCheck(false)
    // Vendored upstream shell suites (jq shtest, yq acceptance scripts and shunit2) are byte-for-byte
    // copies kept unmodified for conformance; they are third-party code and must not be linted or fixed.
    ->withIgnoredPaths('tests/Conformance/Jq/shell', 'tests/Conformance/Yq/acceptance')
;
