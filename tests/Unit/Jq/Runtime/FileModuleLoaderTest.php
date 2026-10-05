<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FileModuleLoader::class)]
final class FileModuleLoaderTest extends TestCase
{
    use AssertsRaised;

    private string $modules;

    protected function setUp(): void
    {
        $this->modules = \dirname(__DIR__, 3) . '/Conformance/Jq/modules';
    }

    public function testFindsAModuleInALibraryDirectory(): void
    {
        $module = $this->loader($this->modules)->loadLibrary('a', null, null);

        self::assertSame(realpath($this->modules . '/a.jq'), $module->path);
        self::assertCount(1, $module->program->defs);
        self::assertSame('a', $module->program->defs[0]->name);
        self::assertNotNull($module->program->module);
    }

    public function testFindsADirectoryModuleByItsOwnName(): void
    {
        $module = $this->loader($this->modules)->loadLibrary('c', null, null);

        self::assertSame(realpath($this->modules . '/c/c.jq'), $module->path);
    }

    public function testSearchMetadataIsRelativeToTheImportingFile(): void
    {
        $importer = $this->modules . '/c/c.jq';
        $loader   = $this->loader();

        $module = $loader->loadLibrary('d', './', $importer);
        self::assertSame(realpath($this->modules . '/c/d.jq'), $module->path);

        $viaList = $loader->loadLibrary('e', ['./../lib/jq'], $importer);
        self::assertSame(realpath($this->modules . '/lib/jq/e/e.jq'), $viaList->path);
    }

    public function testSearchMetadataComesBeforeTheLibraryPath(): void
    {
        $module = $this->loader($this->modules . '/b')->loadLibrary('d', './', $this->modules . '/c/c.jq');

        self::assertSame(realpath($this->modules . '/c/d.jq'), $module->path);
    }

    public function testLoadsDataFiles(): void
    {
        $values = $this->loader($this->modules)->loadData('data', null, null);

        self::assertEquals([new JsonObject(['this' => 'is a test', 'that' => 'is too'])], $values);
    }

    public function testMissingModule(): void
    {
        self::assertRaises(JqCompileException::class, 'module not found: nonexistent (searched: ' . $this->modules . ')', fn (): mixed => $this->loader($this->modules)->loadLibrary('nonexistent', null, null));
    }

    public function testMissingDataFile(): void
    {
        self::assertRaises(JqCompileException::class, 'module not found: nonexistent (searched: ' . $this->modules . ')', fn (): mixed => $this->loader($this->modules)->loadData('nonexistent', null, null));
    }

    public function testRefusesParentDirectoryTraversal(): void
    {
        self::assertRaises(JqCompileException::class, 'Relative paths to modules may not traverse to parent directories (../a)', fn (): mixed => $this->loader($this->modules)->loadLibrary('../a', null, null));
    }

    public function testRefusesEqualConsecutiveComponents(): void
    {
        self::assertRaises(JqCompileException::class, 'module names must not have equal consecutive components: foo/foo', fn (): mixed => $this->loader($this->modules)->loadLibrary('foo/foo', null, null));
    }

    public function testSyntaxErrorsNameTheModule(): void
    {
        try {
            $this->loader($this->modules)->loadLibrary('syntaxerror', null, null);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertStringContainsString('syntaxerror.jq', $jqCompileException->getMessage());
            self::assertNotNull($jqCompileException->getPrevious());
        }
    }

    public function testWithoutLibraryPathsTheDefaultsAreSearched(): void
    {
        try {
            $this->loader()->loadLibrary('nonexistent', null, null);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertStringContainsString('/.jq', $jqCompileException->getMessage());
            self::assertStringContainsString('/bin/../lib/jq', $jqCompileException->getMessage());
        }
    }

    public function testRelativeImportsOfTheMainProgramUseTheWorkingDirectory(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        chdir($this->modules);

        try {
            $module = $this->loader()->loadLibrary('a', './', null);
        } finally {
            chdir($cwd);
        }

        self::assertSame(realpath($this->modules . '/a.jq'), $module->path);
    }

    public function testHomeDirectoryIsExpandedInLibraryPaths(): void
    {
        $home = getenv('HOME');
        putenv('HOME=' . $this->modules);

        try {
            $module = $this->loader('~')->loadLibrary('a', null, null);
            self::assertSame(realpath($this->modules . '/a.jq'), $module->path);

            try {
                $this->loader('~/b')->loadLibrary('nonexistent', null, null);
                self::fail('expected a compile error');
            } catch (JqCompileException $jqCompileException) {
                self::assertStringContainsString($this->modules . '/b', $jqCompileException->getMessage());
                self::assertStringNotContainsString('~/b', $jqCompileException->getMessage());
            }
        } finally {
            putenv(false === $home ? 'HOME' : 'HOME=' . $home);
        }
    }

    public function testOriginIsTheProjectBinDirectory(): void
    {
        $module = $this->loader('$ORIGIN/../tests/Conformance/Jq/modules')->loadLibrary('a', null, null);

        self::assertSame(realpath($this->modules . '/a.jq'), $module->path);

        try {
            $this->loader('$ORIGIN')->loadLibrary('nonexistent', null, null);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertStringContainsString(\dirname(__DIR__, 4) . '/bin', $jqCompileException->getMessage());
        }
    }

    private function loader(string ...$libraryPaths): FileModuleLoader
    {
        return new FileModuleLoader(array_values($libraryPaths), new Parser(new Lexer()), new JsonDecoder());
    }
}
