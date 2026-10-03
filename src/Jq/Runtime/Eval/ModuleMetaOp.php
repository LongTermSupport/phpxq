<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\ImportKind;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\ModuleLoaderInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * `modulemeta`: for a module name, its `module` directive metadata plus `deps` (every import) and `defs`
 * (the names and arities of its top-level definitions).
 *
 * @internal
 */
final class ModuleMetaOp extends AbstractSingleOp
{
    public function __construct(private readonly ModuleLoaderInterface $loader)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        if (!\is_string($input)) {
            throw new JqException('modulemeta input module name must be a string');
        }

        try {
            $module = $this->loader->loadLibrary($input, null, null);
        } catch (JqCompileException $jqCompileException) {
            throw new JqException($jqCompileException->getMessage());
        }

        $program = $module->program;
        $meta    = $program->module?->metadata;
        $result  = $meta instanceof JsonObject ? $meta : new JsonObject();

        $dependencies = [];
        foreach ($program->imports as $import) {
            $dependency = $import->metadata instanceof JsonObject ? $import->metadata : new JsonObject();
            if (null !== $import->alias) {
                $dependency = $dependency->with('as', $import->alias);
            }

            $dependencies[] = $dependency
                ->with('is_data', ImportKind::Data === $import->kind)
                ->with('relpath', $import->path)
            ;
        }

        $definitions = [];
        foreach ($program->defs as $definition) {
            $definitions[] = $definition->signature();
        }

        return $result->with('deps', $dependencies)->with('defs', $definitions);
    }
}
