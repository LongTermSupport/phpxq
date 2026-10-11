<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\RefusingModuleLoader;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RefusingModuleLoaderTest extends TestCase
{
    public function testLibrariesAreRefused(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/modules are disabled/');

        new RefusingModuleLoader()->loadLibrary('m', null, null);
    }

    public function testDataFilesAreRefused(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/modules are disabled/');

        new RefusingModuleLoader()->loadData('d', null, null);
    }
}
