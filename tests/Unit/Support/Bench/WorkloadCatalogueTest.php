<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\CorpusGenerator;
use LTS\PhpXq\Tests\Support\Bench\WorkloadCatalogue;
use LTS\PhpXq\Tests\Support\Bench\WorkloadSelection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class WorkloadCatalogueTest extends TestCase
{
    public function testEveryWorkloadUsesAKnownCorpusAndToolAndIsUnique(): void
    {
        $ids = [];
        foreach (new WorkloadCatalogue()->all() as $workload) {
            self::assertContains($workload->corpus, CorpusGenerator::CORPORA);
            self::assertContains($workload->tool, ['jq', 'yq']);
            self::assertStringStartsWith($workload->tool . ':', $workload->id);
            self::assertGreaterThanOrEqual(1, $workload->batch);
            $ids[] = $workload->id;
        }

        self::assertCount(\count($ids), array_unique($ids));
    }

    public function testBothToolsHaveStartupAndThroughputWorkloads(): void
    {
        $ids = array_map(static fn (\LTS\PhpXq\Tests\Support\Bench\Workload $w): string => $w->id, new WorkloadCatalogue()->all());

        foreach (['jq', 'yq'] as $tool) {
            self::assertContains($tool . ':startup', $ids);
            self::assertContains($tool . ':many-small', $ids);
            self::assertContains($tool . ':identity-medium', $ids);
        }
    }

    public function testDefaultSelectionExcludesLargeCorpus(): void
    {
        $selected = new WorkloadCatalogue()->select(new WorkloadSelection());

        foreach ($selected as $workload) {
            self::assertNotSame('large', $workload->corpus);
        }

        self::assertNotSame([], $selected);
    }

    public function testSelectionFiltersBySizeToolAndName(): void
    {
        $catalogue = new WorkloadCatalogue();

        $large = $catalogue->select(new WorkloadSelection(sizes: ['large'], tools: ['jq']));
        self::assertNotSame([], $large);
        foreach ($large as $workload) {
            self::assertSame('jq', $workload->tool);
            self::assertContains($workload->corpus, ['tiny', 'wide', 'deep', 'large']);
        }

        $named = $catalogue->select(new WorkloadSelection(nameContains: 'startup'));
        self::assertCount(2, $named);
    }

    public function testPlanLinesAreTabSeparatedWithInputPathPerTool(): void
    {
        $plan  = new WorkloadCatalogue()->planTsv('/data', new WorkloadSelection(tools: ['yq'], nameContains: 'startup'));
        $parts = explode("\t", trim($plan));

        self::assertSame(['yq:startup', 'yq', '/data/tiny.yaml', '1', '.'], $parts);
    }
}
