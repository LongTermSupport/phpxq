<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * Describes the machine and PHP configuration a run happened on. Results from different environments
 * are not comparable, so this is stored with every run. OPcache and JIT settings are the ones that swing
 * PHP timings the most.
 */
final readonly class EnvironmentProbe
{
    public function __construct(private string $cpuinfoPath = '/proc/cpuinfo')
    {
    }

    /**
     * @return array<string, string>
     */
    public function collect(): array
    {
        $cpuinfo = is_readable($this->cpuinfoPath) ? (string)file_get_contents($this->cpuinfoPath) : '';

        return [
            'php_version'        => \PHP_VERSION,
            'php_binary'         => \PHP_BINARY,
            'php_sapi'           => \PHP_SAPI,
            'opcache_loaded'     => $this->flag(\extension_loaded('Zend OPcache')),
            'opcache_enable_cli' => $this->ini('opcache.enable_cli'),
            'jit'                => $this->ini('opcache.jit'),
            'jit_buffer_size'    => $this->ini('opcache.jit_buffer_size'),
            'xdebug_loaded'      => $this->flag(\extension_loaded('xdebug')),
            'memory_limit'       => $this->ini('memory_limit'),
            'cpu_model'          => $this->cpuModel($cpuinfo),
            'cpu_cores'          => (string)substr_count($cpuinfo, "processor\t"),
            'kernel'             => php_uname('r'),
            'os'                 => php_uname('s'),
            'hostname'           => php_uname('n'),
        ];
    }

    private function flag(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function ini(string $name): string
    {
        $value = \ini_get($name);

        return false === $value ? 'n/a' : $value;
    }

    private function cpuModel(string $cpuinfo): string
    {
        if (1 === preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $matches)) {
            return trim($matches[1]);
        }

        return 'unknown';
    }
}
