<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Qa;

use LTS\PhpXq\Qa\FileChange;
use LTS\PhpXq\Qa\MutationScope;
use LTS\PhpXq\Qa\ScopeKindEnum;
use LTS\PhpXq\Qa\ScopeResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MutationScopeTest extends TestCase
{
    private const array SOURCES = [
        'src/Cli/ErrorGuard.php',
        'src/Jq/Cli/InputSource.php',
        'src/Jq/Cli/OutputWriter.php',
        'src/Jq/Parser/Parser.php',
        'src/Jq/Runtime/Eval/ObjectOp.php',
        'src/Jq/Runtime/Eval/Support/Helper.php',
        'src/Yaml/Node.php',
        'src/Yaml/Parser/StreamParser.php',
    ];

    public function testAQuotedPathIsNeverReadAsOutsideTheCodeSoEverythingIsMutated(): void
    {
        // git quotes a path with unusual bytes unless asked for -z output; such a path must not fall through to "none".
        $scope = $this->scope(new FileChange('A', '"src/Jq/\303\234ber.php"'));

        self::assertSame(ScopeKindEnum::All, $scope->kind);
    }

    public function testUnparseableDiffOutputMutatesEverything(): void
    {
        self::assertSame(ScopeKindEnum::All, new MutationScope(self::SOURCES)->resolveNameStatusZ("A\t\"src/Jq/\\303\\234ber.php\"\n")->kind);
    }

    public function testNameStatusZOutputIsResolved(): void
    {
        $scope = new MutationScope(['src/Jq/Über.php', 'src/Yaml/Node.php'])->resolveNameStatusZ("A\0src/Jq/Über.php\0A\0tests/Unit/Jq/ÜberTest.php\0");

        self::assertSame(['src/Jq/Über.php'], $scope->files);
    }

    public function testANonAsciiSourceFileIsMutated(): void
    {
        $scope = new MutationScope(['src/Jq/Über.php', 'src/Yaml/Node.php'])->resolve(new FileChange('A', 'src/Jq/Über.php'));

        self::assertSame(['src/Jq/Über.php'], $scope->files);
    }

    public function testAChangeOutsideCodeAndTestsNeedsNoMutation(): void
    {
        $scope = $this->scope(new FileChange('M', 'README.md'), new FileChange('M', '.github/workflows/qa.yml'), new FileChange('M', 'qaConfig/phpstan.neon'));

        self::assertSame(ScopeKindEnum::None, $scope->kind);
        self::assertSame([], $scope->files);
    }

    public function testAddedModifiedAndRenamedSourceFilesAreMutatedButADeletedOneCannotBe(): void
    {
        $scope = $this->scope(
            new FileChange('A', 'src/Yaml/Node.php'),
            new FileChange('M', 'src/Jq/Parser/Parser.php'),
            new FileChange('R', 'src/Cli/ErrorGuard.php'),
            new FileChange('D', 'src/Jq/Gone.php'),
            new FileChange('M', 'src/PHPStan/CLAUDE.md'),
        );

        self::assertSame(ScopeKindEnum::Files, $scope->kind);
        self::assertSame(['src/Cli/ErrorGuard.php', 'src/Jq/Parser/Parser.php', 'src/Yaml/Node.php'], $scope->files);
    }

    public function testATestMapsToTheSourceFileItMirrors(): void
    {
        $scope = $this->scope(new FileChange('M', 'tests/Unit/Jq/Cli/InputSourceTest.php'));

        self::assertSame(['src/Jq/Cli/InputSource.php'], $scope->files);
    }

    public function testADeletedTestStillMapsToTheCodeItGuarded(): void
    {
        $scope = $this->scope(new FileChange('D', 'tests/Unit/Yaml/NodeTest.php'));

        self::assertSame(['src/Yaml/Node.php'], $scope->files);
    }

    public function testATestMirroringADirectoryMapsToEveryFileInIt(): void
    {
        $scope = $this->scope(new FileChange('M', 'tests/Unit/Jq/Runtime/EvalTest.php'));

        self::assertSame(['src/Jq/Runtime/Eval/ObjectOp.php', 'src/Jq/Runtime/Eval/Support/Helper.php'], $scope->files);
    }

    public function testATestWithNoMirroredFileMapsToTheNearestMirroredDirectory(): void
    {
        $scope = $this->scope(new FileChange('M', 'tests/Unit/Jq/Cli/JqApplicationInputSourceTest.php'));

        self::assertSame(['src/Jq/Cli/InputSource.php', 'src/Jq/Cli/OutputWriter.php'], $scope->files);
    }

    public function testATestHelperWalksUpToTheNearestDirectoryThatExistsUnderSrc(): void
    {
        $scope = $this->scope(new FileChange('M', 'tests/Unit/Yaml/Parser/Deep/Support/Builder.php'));

        self::assertSame(['src/Yaml/Parser/StreamParser.php'], $scope->files);
    }

    public function testAFixtureMapsToTheSourceDirectoryItMirrors(): void
    {
        $scope = $this->scope(new FileChange('M', 'tests/Fixtures/Yaml/anchors.yml'));

        self::assertSame(['src/Yaml/Node.php', 'src/Yaml/Parser/StreamParser.php'], $scope->files);
    }

    #[DataProvider('everythingTriggers')]
    public function testSharedTestInfrastructureAndBuildConfigurationMutateEverything(string $path): void
    {
        $scope = $this->scope(new FileChange('M', 'src/Yaml/Node.php'), new FileChange('M', $path));

        self::assertSame(ScopeKindEnum::All, $scope->kind);
        self::assertSame(self::SOURCES, $scope->files);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everythingTriggers(): iterable
    {
        yield 'test support code' => ['tests/Support/CliRunner.php'];

        yield 'test bootstrap' => ['tests/bootstrap.php'];

        yield 'phpunit configuration' => ['qaConfig/phpunit.xml'];

        yield 'qa floors' => ['qaConfig/qa.php'];

        yield 'composer manifest' => ['composer.json'];

        yield 'composer lock' => ['composer.lock'];

        yield 'a unit test of a top-level directory src does not have' => ['tests/Unit/Unknown/ThingTest.php'];

        yield 'an unrecognised test path' => ['tests/Other/Thing.php'];
    }

    #[DataProvider('pathsGuardingNoSource')]
    public function testTestsOfCodeOutsideSrcNeedNoMutation(string $path): void
    {
        self::assertSame(ScopeKindEnum::None, $this->scope(new FileChange('M', $path))->kind);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsGuardingNoSource(): iterable
    {
        yield 'qaConfig rule test' => ['tests/Unit/QaConfig/PHPStan/Rules/SomeRuleTest.php'];

        yield 'release tooling test' => ['tests/Unit/Release/SemVerTest.php'];

        yield 'test support test' => ['tests/Unit/Support/Bench/BenchCliTest.php'];

        yield 'qa tooling test' => ['tests/Unit/Qa/MutationScopeTest.php'];

        yield 'conformance (records no coverage)' => ['tests/Conformance/Yq/known-gaps.txt'];

        yield 'defence fixture' => ['tests/Fixtures/Defence/AliasRecursion/Fixture.php'];
    }

    public function testExcludesListEverySourceFileOutsideTheScopeRelativeToSrc(): void
    {
        $scope = $this->scope(new FileChange('M', 'src/Yaml/Node.php'));

        self::assertSame(
            [
                'Cli/ErrorGuard.php',
                'Jq/Cli/InputSource.php',
                'Jq/Cli/OutputWriter.php',
                'Jq/Parser/Parser.php',
                'Jq/Runtime/Eval/ObjectOp.php',
                'Jq/Runtime/Eval/Support/Helper.php',
                'Yaml/Parser/StreamParser.php',
            ],
            $scope->excludes(),
        );
    }

    public function testAnExcludeThatWouldAlsoMatchAFileInScopeIsLeftOut(): void
    {
        // Infection matches excludes as substrings of the relative path, so excluding Cli/InputSource.php would also
        // drop the in-scope Jq/Cli/InputSource.php: the outside file is mutated as well rather than the other skipped.
        $scope = new MutationScope(['src/Cli/InputSource.php', 'src/Jq/Cli/InputSource.php', 'src/Yaml/Node.php'])
            ->resolve(new FileChange('M', 'src/Jq/Cli/InputSource.php'))
        ;

        self::assertSame(['Yaml/Node.php'], $scope->excludes());
    }

    public function testEverythingInScopeExcludesNothing(): void
    {
        self::assertSame([], $this->scope(new FileChange('M', 'composer.json'))->excludes());
    }

    private function scope(FileChange ...$changes): ScopeResult
    {
        return new MutationScope(self::SOURCES)->resolve(...$changes);
    }
}
