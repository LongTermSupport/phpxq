<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

interface ConformanceSuiteInterface
{
    /**
     * @return string the tool this suite covers, `jq` or `yq`
     */
    public function name(): string;

    /**
     * @return iterable<ConformanceCase>
     */
    public function cases(): iterable;
}
