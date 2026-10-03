<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Bench\CorpusGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CorpusGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpxq-corpus-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach ((array)scandir($this->dir) as $entry) {
            if (is_file($this->dir . '/' . $entry)) {
                unlink($this->dir . '/' . $entry);
            }
        }

        rmdir($this->dir);
    }

    public function testOutputIsDeterministic(): void
    {
        $generator = new CorpusGenerator();

        foreach (['tiny', 'small', 'wide', 'deep'] as $corpus) {
            self::assertSame($generator->json($corpus), new CorpusGenerator()->json($corpus), $corpus);
            self::assertSame($generator->yaml($corpus), new CorpusGenerator()->yaml($corpus), $corpus);
        }
    }

    public function testSmallRecordsAreValidJsonWithExpectedShape(): void
    {
        $decoded = json_decode(new CorpusGenerator()->json('small'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertCount(100, $decoded);
        $first = $decoded[0];
        $last  = $decoded[99];
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertSame(['id', 'name', 'group', 'active', 'score', 'tags', 'nested'], array_keys($first));
        self::assertSame(99, $last['id']);
    }

    public function testSizesGrow(): void
    {
        $generator = new CorpusGenerator();

        self::assertLessThan(\strlen($generator->json('small')), \strlen($generator->json('tiny')));
        self::assertLessThan(\strlen($generator->json('medium')), \strlen($generator->json('small')));
    }

    public function testDeepCorpusNestsAndWideCorpusIsFlat(): void
    {
        $generator = new CorpusGenerator();
        $deep      = json_decode($generator->json('deep'), true, 512, \JSON_THROW_ON_ERROR);
        $wide      = json_decode($generator->json('wide'), true, 512, \JSON_THROW_ON_ERROR);

        $depth = 0;
        while (\is_array($deep) && isset($deep['child'])) {
            $deep = $deep['child'];
            ++$depth;
        }

        self::assertSame(100, $depth);
        self::assertIsArray($wide);
        self::assertCount(20_000, $wide);
    }

    public function testYamlRecordsCarryTheSameRecordCount(): void
    {
        $yaml = new CorpusGenerator()->yaml('small');

        self::assertSame(100, substr_count($yaml, "\n- id: ") + 1);
        self::assertStringStartsWith("- id: 0\n  name: user-0\n", $yaml);
    }

    public function testGenerateWritesFilesAndIsIdempotent(): void
    {
        $generator = new CorpusGenerator();
        $paths     = $generator->generate($this->dir, 'tiny');

        self::assertFileExists($paths['json']);
        self::assertFileExists($paths['yaml']);
        self::assertSame($generator->json('tiny'), file_get_contents($paths['json']));

        touch($paths['json'], 1_000_000_000);
        clearstatcache();
        self::assertSame($paths, $generator->generate($this->dir, 'tiny'));
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($paths['json']));
    }

    public function testUnknownCorpusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorpusGenerator()->generate($this->dir, 'gigantic');
    }
}
