<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileModuleLoader::class)]
final class FileModuleLoaderTest extends TestCase
{
    private string $modules;

    protected function setUp(): void
    {
        $this->modules = \dirname(__DIR__, 3) . '/Conformance/Jq/modules';
    }

    public function testFindsAModuleInALibraryDirectory(): void
    {
        $module = $this->loader([$this->modules])->loadLibrary('a', null, null);

        self::assertSame(realpath($this->modules . '/a.jq'), $module->path);
        self::assertCount(1, $module->program->defs);
        self::assertSame('a', $module->program->defs[0]->name);
        self::assertNotNull($module->program->module);
    }

    public function testFindsADirectoryModuleByItsOwnName(): void
    {
        $module = $this->loader([$this->modules])->loadLibrary('c', null, null);

        self::assertSame(realpath($this->modules . '/c/c.jq'), $module->path);
    }

    public function testSearchMetadataIsRelativeToTheImportingFile(): void
    {
        $importer = $this->modules . '/c/c.jq';
        $loader   = $this->loader([]);

        $module = $loader->loadLibrary('d', './', $importer);
        self::assertSame(realpath($this->modules . '/c/d.jq'), $module->path);

        $viaList = $loader->loadLibrary('e', ['./../lib/jq'], $importer);
        self::assertSame(realpath($this->modules . '/lib/jq/e/e.jq'), $viaList->path);
    }

    public function testSearchMetadataComesBeforeTheLibraryPath(): void
    {
        $module = $this->loader([$this->modules . '/b'])->loadLibrary('d', './', $this->modules . '/c/c.jq');

        self::assertSame(realpath($this->modules . '/c/d.jq'), $module->path);
    }

    public function testLoadsDataFiles(): void
    {
        $values = $this->loader([$this->modules])->loadData('data', null, null);

        self::assertEquals([new JsonObject(['this' => 'is a test', 'that' => 'is too'])], $values);
    }

    public function testMissingModule(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessage('module not found: nonexistent');

        $this->loader([$this->modules])->loadLibrary('nonexistent', null, null);
    }

    public function testMissingDataFile(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessage('module not found: nonexistent');

        $this->loader([$this->modules])->loadData('nonexistent', null, null);
    }

    public function testRefusesParentDirectoryTraversal(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessage('Relative paths to modules may not traverse to parent directories (../a)');

        $this->loader([$this->modules])->loadLibrary('../a', null, null);
    }

    public function testRefusesEqualConsecutiveComponents(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessage('module names must not have equal consecutive components: foo/foo');

        $this->loader([$this->modules])->loadLibrary('foo/foo', null, null);
    }

    public function testSyntaxErrorsNameTheModule(): void
    {
        try {
            $this->loader([$this->modules])->loadLibrary('syntaxerror', null, null);
            self::fail('expected a compile error');
        } catch (JqCompileException $exception) {
            self::assertStringContainsString('syntaxerror.jq', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testWithoutLibraryPathsTheDefaultsAreSearched(): void
    {
        try {
            $this->loader([])->loadLibrary('nonexistent', null, null);
            self::fail('expected a compile error');
        } catch (JqCompileException $exception) {
            self::assertStringContainsString('/.jq', $exception->getMessage());
            self::assertStringContainsString('/bin/../lib/jq', $exception->getMessage());
        }
    }

    public function testRelativeImportsOfTheMainProgramUseTheWorkingDirectory(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        chdir($this->modules);

        try {
            $module = $this->loader([])->loadLibrary('a', './', null);
        } finally {
            chdir($cwd);
        }

        self::assertSame(realpath($this->modules . '/a.jq'), $module->path);
    }

    /**
     * @param list<string> $libraryPaths
     */
    private function loader(array $libraryPaths): FileModuleLoader
    {
        return new FileModuleLoader($libraryPaths, new Parser(new Lexer()), new JsonDecoder());
    }
}
