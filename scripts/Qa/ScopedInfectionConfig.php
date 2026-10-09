<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

use UnexpectedValueException;

/**
 * Builds php-qa-ci's Infection project override (qaConfig/infection.json) for a scoped run from its generic config:
 * every path made absolute (the generic ones are relative to the generic directory, the override lives elsewhere),
 * every out-of-scope source file excluded, and a scope with no mutants (only interfaces, say) reported as such rather
 * than as a mutation score of zero. Infection's PHPUnit config directory stays the generic one, as in a full run.
 *
 * The generic config is refused unless it has the shape this relies on, so a php-qa-ci change to it fails the scoped
 * run loudly instead of writing a config that measures something else.
 */
final readonly class ScopedInfectionConfig
{
    private const string SOURCE = 'source';

    private const string DIRECTORIES = 'directories';

    private const string LOGS = 'logs';

    private const string PHPUNIT = 'phpUnit';

    private const string CONFIG_DIR = 'configDir';

    private const string TMP_DIR = 'tmpDir';

    private const string SEPARATOR = '/';

    /**
     * @param mixed $generic the decoded generic infection.json
     *
     * @return array<string, mixed>
     */
    public function build(mixed $generic, string $genericDir, ScopeResult $scope): array
    {
        $config  = self::stringKeyed($generic, 'the generic Infection config');
        $source  = self::stringKeyed($config[self::SOURCE] ?? null, self::SOURCE);
        $logs    = self::stringKeyed($config[self::LOGS] ?? null, self::LOGS);
        $phpUnit = self::stringKeyed($config[self::PHPUNIT] ?? null, self::PHPUNIT);

        $directories = [];
        foreach (self::listOf($source[self::DIRECTORIES] ?? null, 'source.directories') as $directory) {
            $directories[] = self::absolute(self::string($directory, 'source.directories[]'), $genericDir);
        }

        $source[self::DIRECTORIES] = $directories;
        $source['excludes']        = $scope->excludes();

        foreach ($logs as $name => $path) {
            $logs[$name] = self::absolute(self::string($path, 'logs.' . $name), $genericDir);
        }

        $phpUnit[self::CONFIG_DIR] = self::absolute(self::string($phpUnit[self::CONFIG_DIR] ?? null, 'phpUnit.configDir'), $genericDir);

        $config[self::SOURCE]             = $source;
        $config[self::LOGS]               = $logs;
        $config[self::PHPUNIT]            = $phpUnit;
        $config[self::TMP_DIR]            = self::absolute(self::string($config[self::TMP_DIR] ?? null, self::TMP_DIR), $genericDir);
        $config['ignoreMsiWithNoMutations'] = true;

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value, string $what): array
    {
        if (!\is_array($value)) {
            throw new UnexpectedValueException($what . ' is not a JSON object');
        }

        $keyed = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new UnexpectedValueException($what . ' is not a JSON object');
            }

            $keyed[$key] = $item;
        }

        return $keyed;
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value, string $what): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            throw new UnexpectedValueException($what . ' is not a non-empty JSON array');
        }

        return $value;
    }

    private static function string(mixed $value, string $what): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new UnexpectedValueException($what . ' is not a non-empty string');
        }

        return $value;
    }

    private static function absolute(string $path, string $genericDir): string
    {
        $joined = str_starts_with($path, self::SEPARATOR) ? $path : $genericDir . self::SEPARATOR . $path;
        $parts  = [];
        foreach (explode(self::SEPARATOR, $joined) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }

        return self::SEPARATOR . implode(self::SEPARATOR, $parts) . (str_ends_with($path, self::SEPARATOR) ? self::SEPARATOR : '');
    }
}
