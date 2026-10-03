<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ImportDirective;
use LTS\PhpXq\Jq\Ast\ImportKind;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImportDirectiveTest extends TestCase
{
    public function testCarriesPathAliasKindAndMetadata(): void
    {
        $directive = new ImportDirective('a', 'alias', ImportKind::Data, ['search' => './']);

        self::assertSame('a', $directive->path);
        self::assertSame('alias', $directive->alias);
        self::assertSame(ImportKind::Data, $directive->kind);
        self::assertSame(['search' => './'], $directive->metadata);
    }
}
