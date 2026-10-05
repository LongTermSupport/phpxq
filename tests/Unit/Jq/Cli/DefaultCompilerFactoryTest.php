<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\DefaultCompilerFactory;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Json\JsonDecoder;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DefaultCompilerFactoryTest extends TestCase
{
    public function testBuildsACompilerForGivenLibraryPaths(): void
    {
        $factory = new DefaultCompilerFactory(new Parser(new Lexer()), new JsonDecoder());

        self::assertNotSame($factory->create('/lib'), $factory->create('/lib'));
    }
}
