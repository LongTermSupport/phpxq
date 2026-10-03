<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Yq;

use LTS\PhpXq\Tests\Support\Yq\YqFixtureGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class YqFixtureGeneratorTest extends TestCase
{
    private const string PATTERN = '*.md';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpxq-yq-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/pkg/yqlib/doc/operators', 0o777, true);
        mkdir($this->root . '/pkg/yqlib/doc/usage/headers', 0o777, true);
    }

    protected function tearDown(): void
    {
        $doc = $this->root . '/pkg/yqlib/doc';
        foreach (['operators', 'usage/headers', 'usage'] as $dir) {
            $files = glob($doc . '/' . $dir . '/' . self::PATTERN);
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }

            rmdir($doc . '/' . $dir);
        }

        rmdir($doc);
        rmdir($this->root . '/pkg/yqlib');
        rmdir($this->root . '/pkg');
        rmdir($this->root);
    }

    public function testReadsOperatorsAndUsageInSortedOrderAndIgnoresHeaders(): void
    {
        $page = static fn (string $expr): string => "## Case\nRunning\n```bash\nyq -n '{$expr}'\n```\nwill output\n```yaml\n1\n```\n";
        file_put_contents($this->root . '/pkg/yqlib/doc/operators/zeta.md', $page('1'));
        file_put_contents($this->root . '/pkg/yqlib/doc/operators/alpha.md', $page('2'));
        file_put_contents($this->root . '/pkg/yqlib/doc/usage/beta.md', $page('3'));
        file_put_contents($this->root . '/pkg/yqlib/doc/usage/headers/beta.md', $page('4'));

        $extraction = new YqFixtureGenerator()->generate($this->root);

        $sources = [];
        foreach ($extraction->cases as $case) {
            $sources[] = $case->source;
        }

        self::assertSame(['operators/alpha.md', 'operators/zeta.md', 'usage/beta.md'], $sources);
        self::assertSame([], $extraction->skipped);
    }

    public function testJsonIsPrettyAndStable(): void
    {
        file_put_contents($this->root . '/pkg/yqlib/doc/operators/a.md', "## C\nRunning\n```bash\nyq -n '1'\n```\nwill output\n```yaml\n1\n```\n");

        $extraction = new YqFixtureGenerator()->generate($this->root);

        $json = $extraction->casesJson();
        self::assertStringEndsWith("]\n", $json);
        self::assertStringContainsString("\n    {\n        \"name\": \"operators/a.md: C\"", $json);
        self::assertSame($json, new YqFixtureGenerator()->generate($this->root)->casesJson());
        self::assertSame("[]\n", $extraction->skippedJson());
    }

    public function testMissingCloneIsReported(): void
    {
        $this->expectException(RuntimeException::class);

        new YqFixtureGenerator()->generate($this->root . '/nope');
    }
}
