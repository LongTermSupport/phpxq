<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * Renders a RunRecord as a Markdown report: environment, targets, then one table per workload with
 * timings, the ratio against the reference tool and, when a baseline is given, against the baseline.
 * A ratio above 1.00x means slower.
 */
final class ReportRenderer
{
    public function render(RunRecord $run, ?RunRecord $baseline): string
    {
        $out  = "# phpxq benchmark report\n\n";
        $out .= \sprintf("Label: `%s`  \nStarted: %s\n", $run->label, $run->startedAt);
        if ($baseline instanceof RunRecord) {
            $out .= \sprintf("Baseline: `%s` (%s)\n", $baseline->label, $baseline->startedAt);
            if ($this->environmentDiffers($run, $baseline)) {
                $out .= "\n> WARNING: the baseline was recorded in a different environment (CPU or PHP version);"
                    . " ratios against it are not meaningful.\n";
            }
        }

        $out .= "\n## Environment\n\n| setting | value |\n| --- | --- |\n";
        foreach (array_merge($run->environment, $run->settings) as $key => $value) {
            $out .= \sprintf("| %s | %s |\n", $key, $this->cell($value));
        }

        $out .= "\n## Targets\n\n| id | role | version | command |\n| --- | --- | --- | --- |\n";
        foreach ($run->targets as $target) {
            $out .= \sprintf("| %s | %s | %s | %s |\n", $target->id, $target->role, $this->cell($target->version), $this->cell($target->command));
        }

        $out .= "\n## Results\n\nMedian wall-clock milliseconds per sample; `vs ref` is the median relative to the reference tool,"
            . " `vs base` relative to the baseline. Above 1.00x is slower.\n";
        foreach ($this->workloadIds($run) as $workloadId) {
            $out .= $this->workloadTable($run, $baseline, $workloadId);
        }

        return $out;
    }

    private function workloadTable(RunRecord $run, ?RunRecord $baseline, string $workloadId): string
    {
        $out = "\n### " . $workloadId . "\n\n| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |\n| --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($run->measurements as $measurement) {
            if ($measurement->workloadId !== $workloadId) {
                continue;
            }

            $stats = $measurement->stats;
            $out  .= \sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $measurement->targetId,
                $measurement->status,
                null === $stats ? '-' : $this->ms($stats->medianMs),
                null === $stats ? '-' : $this->ms($stats->minMs),
                null === $stats ? '-' : $this->ms($stats->p95Ms),
                null === $stats ? '-' : \sprintf('%.1f', $stats->coefficientOfVariationPercent()),
                $this->ratioAgainstReference($run, $measurement),
                $this->ratioAgainstBaseline($baseline, $measurement),
            );
            if ('' !== $measurement->note && Measurement::STATUS_OK !== $measurement->status) {
                $out .= \sprintf("|  | note: %s | | | | | | |\n", $this->cell($measurement->note));
            }
        }

        return $out;
    }

    private function ratioAgainstReference(RunRecord $run, Measurement $measurement): string
    {
        $target = $run->target($measurement->targetId);
        if (!$target instanceof Target || Target::ROLE_SUBJECT !== $target->role || !$measurement->stats instanceof SampleStats) {
            return '-';
        }

        $reference = $run->find($target->tool, $measurement->workloadId)?->stats;

        return $reference instanceof SampleStats ? $this->ratio($measurement->stats->medianMs, $reference->medianMs) : '-';
    }

    private function ratioAgainstBaseline(?RunRecord $baseline, Measurement $measurement): string
    {
        if (!$baseline instanceof RunRecord || !$measurement->stats instanceof SampleStats) {
            return '-';
        }

        $before = $baseline->find($measurement->targetId, $measurement->workloadId)?->stats;

        return $before instanceof SampleStats ? $this->ratio($measurement->stats->medianMs, $before->medianMs) : '-';
    }

    private function ratio(float $value, float $against): string
    {
        return $against <= 0.0 ? '-' : \sprintf('%.2fx', $value / $against);
    }

    private function ms(float $value): string
    {
        return \sprintf('%.2f', $value);
    }

    private function cell(string $value): string
    {
        return '' === $value ? '-' : str_replace('|', '\|', $value);
    }

    private function environmentDiffers(RunRecord $run, RunRecord $baseline): bool
    {
        return array_any(['cpu_model', 'php_version'], static fn (string $key): bool => ($run->environment[$key] ?? '') !== ($baseline->environment[$key] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function workloadIds(RunRecord $run): array
    {
        $ids = [];
        foreach ($run->measurements as $measurement) {
            $ids[$measurement->workloadId] = true;
        }

        return array_map(strval(...), array_keys($ids));
    }
}
