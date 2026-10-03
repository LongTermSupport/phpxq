<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * The fixed set of workloads. Filters are written so that jq and mikefarah yq accept the same text
 * where they can; the deep-walk filter differs because the two languages type-test differently.
 */
final class WorkloadCatalogue
{
    private const string SELECT = '.[] | select(.active) | .name';

    private const string AGGREGATE = 'map(.score) | unique | length';

    private const string GROUP = 'group_by(.group) | map({"group": .[0].group, "count": length})';

    private const array DEEP_WALK = [
        'jq' => '[.. | numbers] | length',
        'yq' => '[.. | select(tag == "!!int")] | length',
    ];

    /**
     * @return list<Workload>
     */
    public function all(): array
    {
        $workloads = [];
        foreach (['jq', 'yq'] as $tool) {
            $workloads[] = new Workload($tool . ':startup', $tool, 'tiny', '.');
            $workloads[] = new Workload($tool . ':many-small', $tool, 'tiny', '.b[1]', 20);
            foreach (['small', 'medium', 'large'] as $size) {
                $workloads[] = new Workload($tool . ':identity-' . $size, $tool, $size, '.');
                $workloads[] = new Workload($tool . ':select-' . $size, $tool, $size, self::SELECT);
            }

            foreach (['medium', 'large'] as $size) {
                $workloads[] = new Workload($tool . ':aggregate-' . $size, $tool, $size, self::AGGREGATE);
                $workloads[] = new Workload($tool . ':group-' . $size, $tool, $size, self::GROUP);
            }

            $workloads[] = new Workload($tool . ':wide-keys', $tool, 'wide', 'keys | length');
            $workloads[] = new Workload($tool . ':deep-walk', $tool, 'deep', self::DEEP_WALK[$tool]);
        }

        return $workloads;
    }

    /**
     * @return list<Workload>
     */
    public function select(WorkloadSelection $selection): array
    {
        return array_values(array_filter($this->all(), $selection->accepts(...)));
    }

    /**
     * One tab-separated line per workload: id, tool, input path, batch, filter.
     */
    public function planTsv(string $corpusDirectory, WorkloadSelection $selection): string
    {
        $out = '';
        foreach ($this->select($selection) as $workload) {
            $extension = 'jq' === $workload->tool ? 'json' : 'yaml';
            $out .= implode("\t", [
                $workload->id,
                $workload->tool,
                $corpusDirectory . '/' . $workload->corpus . '.' . $extension,
                (string)$workload->batch,
                $workload->filter,
            ]) . "\n";
        }

        return $out;
    }
}
