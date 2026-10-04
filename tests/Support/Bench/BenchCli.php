<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

use InvalidArgumentException;

/**
 * Command-line front of the PHP half of the harness (scripts/bench/bench.php). Spawning and timing the
 * tools is the bash half's job (scripts/bench/bench.bash); this class plans workloads, assembles the
 * recorded measurements into a result file and renders reports.
 *
 * Commands:
 *   plan   --corpus-dir DIR [--sizes a,b] [--tools jq,yq] [--filter TEXT]
 *   record --targets FILE --raw FILE --out FILE [--label L] [--started ISO] [--setting k=v]... [--no-report]
 *   report FILE [--baseline FILE]
 */
final class BenchCli
{
    private const string USAGE = "usage: bench.php plan|record|report [options]\n";

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(mixed $stdout, mixed $stderr, string ...$args): int
    {
        $command = [] === $args ? '' : $args[0];

        try {
            return match ($command) {
                'plan'   => $this->plan($this->options(...\array_slice($args, 1)), $stdout),
                'record' => $this->record($this->options(...\array_slice($args, 1)), $stdout),
                'report' => $this->report(\count($args) > 1 ? $args[1] : '',$this->options(...\array_slice($args, 2)), $stdout),
                default  => $this->fail(self::USAGE, $stderr),
            };
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->fail($invalidArgumentException->getMessage() . "\n", $stderr);
        }
    }

    /**
     * @param array<string, list<string>> $options
     * @param resource                    $stdout
     */
    private function plan(array $options, mixed $stdout): int
    {
        $directory = $this->required($options, 'corpus-dir');
        $defaults  = new WorkloadSelection();
        $selection = new WorkloadSelection(
            sizes: $this->csv($options['sizes'][0] ?? null, ...$defaults->sizes),
            tools: $this->csv($options['tools'][0] ?? null, ...$defaults->tools),
            nameContains: $options['filter'][0] ?? null,
        );

        $catalogue = new WorkloadCatalogue();
        $generator = new CorpusGenerator();
        foreach (array_unique(array_map(static fn (Workload $w): string => $w->corpus, $catalogue->select($selection))) as $corpus) {
            $generator->generate($directory, $corpus);
        }

        fwrite($stdout, $catalogue->planTsv($directory, $selection));

        return 0;
    }

    /**
     * @param array<string, list<string>> $options
     * @param resource                    $stdout
     */
    private function record(array $options, mixed $stdout): int
    {
        $targetsFile = $this->required($options, 'targets');
        $rawFile     = $this->required($options, 'raw');
        $out         = $this->required($options, 'out');

        $settings = [];
        foreach ($options['setting'] ?? [] as $pair) {
            [$key, $value]  = array_pad(explode('=', $pair, 2), 2, '');
            $settings[$key] = $value;
        }

        $parser = new RawResultParser(new StatsCalculator());
        $record = new RunRecord(
            label: $options['label'][0]       ?? 'unlabelled',
            startedAt: $options['started'][0] ?? gmdate('c'),
            environment: new EnvironmentProbe()->collect(),
            settings: $settings,
            targets: $parser->targets($this->read($targetsFile)),
            measurements: $parser->measurements($this->read($rawFile)),
        );

        $store = new ResultStore();
        $store->save($out, $record);
        fwrite($stdout, $out . "\n");

        if (!isset($options['no-report'])) {
            $reportPath = preg_replace('/\.json$/', '', $out) . '.md';
            file_put_contents($reportPath, new ReportRenderer()->render($record, null));
            fwrite($stdout, $reportPath . "\n");
        }

        return 0;
    }

    /**
     * @param array<string, list<string>> $options
     * @param resource                    $stdout
     */
    private function report(string $file, array $options, mixed $stdout): int
    {
        if ('' === $file) {
            throw new InvalidArgumentException('report needs a result file');
        }

        $store    = new ResultStore();
        $baseline = isset($options['baseline'][0]) ? $store->load($options['baseline'][0]) : null;
        fwrite($stdout, new ReportRenderer()->render($store->load($file), $baseline));

        return 0;
    }

    /**
     * @return array<string, list<string>>
     */
    private function options(string ...$args): array
    {
        $options = [];
        $count   = \count($args);
        for ($i = 0; $i < $count; ++$i) {
            if (!str_starts_with($args[$i], '--')) {
                throw new InvalidArgumentException('Unexpected argument: ' . $args[$i]);
            }

            $name = substr($args[$i], 2);
            if ('no-report' === $name) {
                $options[$name][] = '1';

                continue;
            }

            if (!isset($args[$i + 1])) {
                throw new InvalidArgumentException('Option --' . $name . ' needs a value');
            }

            $options[$name][] = $args[++$i];
        }

        return $options;
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function required(array $options, string $name): string
    {
        return $options[$name][0] ?? throw new InvalidArgumentException('Missing required option --' . $name);
    }

    /**
     * @return list<string>
     */
    private function csv(?string $value, string ...$default): array
    {
        if (null === $value || '' === $value) {
            return array_values($default);
        }

        return array_values(array_filter(explode(',', $value), static fn (string $item): bool => '' !== $item));
    }

    private function read(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        return false === $contents ? throw new InvalidArgumentException('Cannot read ' . $path) : $contents;
    }

    /**
     * @param resource $stderr
     */
    private function fail(string $message, mixed $stderr): int
    {
        fwrite($stderr, $message);

        return 2;
    }
}
