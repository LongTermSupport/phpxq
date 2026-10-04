<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every project defence runs over every PHP file of the project without an internal error: a rule that throws on
 * an AST shape it did not expect aborts the whole analysis, which is worse than any single finding it could make.
 * The number of findings is not asserted here; the rules' own tests do that.
 *
 * @internal
 *
 * @extends RuleTestCase<AllDefencesRule>
 */
#[CoversNothing]
#[Large]
final class RulesNeverCrashTest extends RuleTestCase
{
    private const int FILES_PER_CHUNK = 50;

    /**
     * @param list<string> $files
     */
    #[DataProvider('chunksOfProjectFiles')]
    public function testItAnalysesEveryProjectFileWithoutAnInternalError(array $files): void
    {
        // gatherAnalyserErrors() fails the test on an internal error, which is how a throwing rule surfaces.
        $this->gatherAnalyserErrors($files);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function chunksOfProjectFiles(): iterable
    {
        $root  = \dirname(__DIR__, 5);
        $files = [];
        foreach (['src', 'tests'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        foreach (array_chunk($files, self::FILES_PER_CHUNK) as $index => $chunk) {
            yield \sprintf('chunk %03d', $index) => [$chunk];
        }
    }

    protected function getRule(): Rule
    {
        return new AllDefencesRule(self::createReflectionProvider());
    }
}
