<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\ImportDirective;
use LTS\PhpXq\Jq\Ast\ImportKind;
use LTS\PhpXq\Jq\Ast\ModuleDirective;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Runtime\Eval\ModuleMetaOp;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\LoadedModule;
use LTS\PhpXq\Jq\Runtime\ModuleLoaderInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ModuleMetaOp::class)]
final class ModuleMetaOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testDescribesTheModule(): void
    {
        $program = new Program(
            [
                new ImportDirective('a', 'foo', ImportKind::Import),
                new ImportDirective('d', 'd', ImportKind::Import, new JsonObject(['search' => './'])),
                new ImportDirective('data', 'data', ImportKind::Data),
                new ImportDirective('inc', null, ImportKind::Include),
            ],
            new ModuleDirective(new JsonObject(['whatever' => null])),
            [new FuncDef('a', [], new Identity()), new FuncDef('c', ['x'], new Identity())],
            null,
        );

        $result = self::outputs(new ModuleMetaOp(self::loader($program)), 'c')[0];

        self::assertEquals(
            new JsonObject([
                'whatever' => null,
                'deps'     => [
                    new JsonObject(['as' => 'foo', 'is_data' => false, 'relpath' => 'a']),
                    new JsonObject(['search' => './', 'as' => 'd', 'is_data' => false, 'relpath' => 'd']),
                    new JsonObject(['as' => 'data', 'is_data' => true, 'relpath' => 'data']),
                    new JsonObject(['is_data' => false, 'relpath' => 'inc']),
                ],
                'defs'     => ['a/0', 'c/1'],
            ]),
            $result,
        );
    }

    public function testModuleWithoutDirectiveStillListsDepsAndDefs(): void
    {
        $result = self::outputs(new ModuleMetaOp(self::loader(new Program([], null, [], null))), 'm')[0];

        self::assertEquals(new JsonObject(['deps' => [], 'defs' => []]), $result);
    }

    public function testRejectsNonStringInput(): void
    {
        self::assertRaises(JqException::class, 'modulemeta input module name must be a string', static fn (): mixed => self::outputs(new ModuleMetaOp(self::loader(new Program([], null, [], null))), 1));
    }

    public function testLoadFailuresBecomeRuntimeErrors(): void
    {
        $loader = new class implements ModuleLoaderInterface {
            public function loadLibrary(string $relativePath, mixed $searchMetadata, ?string $importerPath): LoadedModule
            {
                throw new JqCompileException('module not found: ' . $relativePath);
            }

            public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array
            {
                return [];
            }
        };

        self::assertRaises(JqException::class, 'module not found: nope', static fn (): mixed => self::outputs(new ModuleMetaOp($loader), 'nope'));
    }

    private static function loader(Program $program): ModuleLoaderInterface
    {
        return new readonly class($program) implements ModuleLoaderInterface {
            public function __construct(private Program $program)
            {
            }

            public function loadLibrary(string $relativePath, mixed $searchMetadata, ?string $importerPath): LoadedModule
            {
                return new LoadedModule($this->program, '/modules/' . $relativePath . '.jq');
            }

            public function loadData(string $relativePath, mixed $searchMetadata, ?string $importerPath): array
            {
                return [];
            }
        };
    }
}
