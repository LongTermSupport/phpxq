<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\EnvironmentProbe;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EnvironmentProbeTest extends TestCase
{
    public function testRecordsPhpAndOpcacheAndJitSettings(): void
    {
        $env = new EnvironmentProbe('/nonexistent/cpuinfo')->collect();

        self::assertSame(\PHP_VERSION, $env['php_version']);
        foreach (['php_binary', 'opcache_loaded', 'opcache_enable_cli', 'jit', 'jit_buffer_size', 'xdebug_loaded', 'memory_limit', 'kernel', 'os', 'hostname', 'cpu_model', 'cpu_cores'] as $key) {
            self::assertArrayHasKey($key, $env);
        }

        self::assertSame('unknown', $env['cpu_model']);
    }

    public function testParsesCpuModelAndCoreCountFromCpuinfo(): void
    {
        $file = sys_get_temp_dir() . '/phpxq-cpuinfo-' . bin2hex(random_bytes(4));
        file_put_contents($file, "processor\t: 0\nmodel name\t: Fancy CPU 9000\nprocessor\t: 1\nmodel name\t: Fancy CPU 9000\n");

        $env = new EnvironmentProbe($file)->collect();
        unlink($file);

        self::assertSame('Fancy CPU 9000', $env['cpu_model']);
        self::assertSame('2', $env['cpu_cores']);
    }
}
