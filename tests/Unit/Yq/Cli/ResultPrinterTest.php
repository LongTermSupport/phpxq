<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Cli\DocumentRegistry;
use LTS\PhpXq\Yq\Cli\ResultPrinter;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use LTS\PhpXq\Yq\Runtime\Candidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResultPrinter::class)]
final class ResultPrinterTest extends TestCase
{
    public function testJsonScalarIsUnwrappedWhenUnwrapIsOn(): void
    {
        self::assertSame("cat\n", $this->print(FormatEnum::Json, true, Node::document(Node::scalar('cat'))));
    }

    public function testJsonScalarStaysQuotedWhenUnwrapIsOff(): void
    {
        self::assertSame("\"cat\"\n", $this->print(FormatEnum::Json, false, Node::document(Node::scalar('cat'))));
    }

    public function testHeaderIsPrintedAheadOfTheDocument(): void
    {
        $document = Node::document(Node::mapping([Node::scalar('a'), Node::scalar('1')]));

        self::assertSame("# top\na: 1\n", $this->print(FormatEnum::Yaml, true, $document, "# top\n"));
    }

    public function testHeaderIsDroppedWhenTheDocumentCommentsWereCleared(): void
    {
        $document                  = Node::document(Node::mapping([Node::scalar('a'), Node::scalar('1')]));
        $document->commentsCleared = true;

        self::assertSame("a: 1\n", $this->print(FormatEnum::Yaml, true, $document, "# top\n"));
    }

    private function print(FormatEnum $format, bool $unwrap, Node $document, string $header = ''): string
    {
        $registry = new DocumentRegistry();
        $registry->register($document, $header, false);

        $out = fopen('php://memory', 'w+b');
        if (false === $out) {
            self::fail('no memory stream');
        }

        $printer = new ResultPrinter(
            $out,
            $format,
            new EmitOptions(unwrapScalar: $unwrap),
            new FormatOptions(unwrapScalar: $unwrap),
            new YamlEmitter(),
            new FormatRegistry(),
            $registry,
            false,
        );
        $printer->print([new Candidate($document, null, null, 0, 0, '')]);

        rewind($out);

        return (string)stream_get_contents($out);
    }
}
