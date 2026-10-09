<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Qa;

use LTS\PhpXq\Qa\FileChange;
use LTS\PhpXq\Qa\MutationScope;
use LTS\PhpXq\Qa\ScopedInfectionConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversNothing]
final class ScopedInfectionConfigTest extends TestCase
{
    private const string GENERIC_DIR = '/project/vendor/lts/php-qa-ci/configDefaults/generic';

    public function testRebasesEveryPathOntoTheGenericDirectoryAndExcludesWhatIsOutOfScope(): void
    {
        $scope = new MutationScope(['src/A.php', 'src/B.php'])->resolve(new FileChange('M', 'src/A.php'));

        $config = new ScopedInfectionConfig()->build(self::generic(), self::GENERIC_DIR, $scope);

        self::assertSame(
            [
                'timeout'                  => 10,
                'source'                   => ['directories' => ['/project/src'], 'excludes' => ['B.php']],
                'mutators'                 => ['@default' => true],
                'logs'                     => ['text' => '/project/var/qa/infection/log.txt', 'summary' => '/project/var/qa/infection/summary-log.txt'],
                'phpUnit'                  => ['configDir' => '/project/vendor/lts/php-qa-ci/configDefaults/generic/'],
                'tmpDir'                   => '/project/var/qa/infection/tmp',
                // A scope of interfaces alone has no mutants; that is a pass, not an MSI of zero.
                'ignoreMsiWithNoMutations' => true,
            ],
            $config,
        );
    }

    /**
     * @param array<string, mixed> $generic
     */
    #[DataProvider('malformedConfigs')]
    public function testRefusesAGenericConfigOfAnotherShape(array $generic): void
    {
        $scope = new MutationScope(['src/A.php'])->resolve(new FileChange('M', 'src/A.php'));

        $this->expectException(UnexpectedValueException::class);

        new ScopedInfectionConfig()->build($generic, self::GENERIC_DIR, $scope);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedConfigs(): iterable
    {
        $generic = self::generic();

        yield 'no source directories' => [['source' => []] + $generic];

        yield 'a source directory that is not a string' => [['source' => ['directories' => [1]]] + $generic];

        yield 'logs that are not an object of strings' => [['logs' => ['text' => ['x']]] + $generic];

        yield 'no phpUnit config directory' => [['phpUnit' => []] + $generic];

        yield 'a tmpDir that is not a string' => [['tmpDir' => false] + $generic];
    }

    public function testRefusesSomethingOtherThanAnObject(): void
    {
        $scope = new MutationScope(['src/A.php'])->resolve(new FileChange('M', 'src/A.php'));

        $this->expectException(UnexpectedValueException::class);

        new ScopedInfectionConfig()->build('not a config', self::GENERIC_DIR, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    private static function generic(): array
    {
        return [
            'timeout'  => 10,
            'source'   => ['directories' => ['../../../../../src']],
            'mutators' => ['@default' => true],
            'logs'     => ['text' => '../../../../../var/qa/infection/log.txt', 'summary' => '../../../../../var/qa/infection/summary-log.txt'],
            'phpUnit'  => ['configDir' => './'],
            'tmpDir'   => '../../../../../var/qa/infection/tmp',
        ];
    }
}
