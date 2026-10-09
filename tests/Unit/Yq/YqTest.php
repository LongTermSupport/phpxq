<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Yq;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YqTest extends TestCase
{
    private const string DOC = "items:\n  - name: a\n    n: 1\n  - name: b\n    n: 2\n";

    public function testEvaluatesAnExpressionAndEmitsYaml(): void
    {
        self::assertSame("b\n", Yq::evaluate('.items[] | select(.n > 1) | .name', self::DOC));
        self::assertSame("name: b\nn: 2\n", Yq::evaluate('.items[1]', self::DOC));
    }

    public function testEmitsJson(): void
    {
        self::assertSame("{\"name\":\"b\",\"n\":2}\n", Yq::evaluate('.items[1]', self::DOC, output: FormatEnum::Json, indent: 0));
        self::assertSame("[\n  \"a\",\n  \"b\"\n]\n", Yq::evaluate('[.items[].name]', self::DOC, output: FormatEnum::Json));
    }

    public function testReadsJsonInput(): void
    {
        self::assertSame("a: 1\n", Yq::evaluate('.', '{"a": 1}', input: FormatEnum::Json));
    }

    public function testUpdatesPreserveComments(): void
    {
        self::assertSame("# keep\na: 2\n", Yq::evaluate('.a = 2', "# keep\na: 1\n"));
    }

    public function testEveryDocumentOfAStreamIsEvaluated(): void
    {
        self::assertSame("1\n---\n2\n", Yq::evaluate('.a', "a: 1\n---\na: 2\n"));
    }

    public function testAVeryLongExpressionDoesNotOverflowTheNativeStack(): void
    {
        self::assertSame("20001\n", Yq::evaluate('.a' . str_repeat(' + 1', 20000), 'a: 1'));
    }

    public function testNoMatchGivesAnEmptyString(): void
    {
        self::assertSame('', Yq::evaluate('.items[] | select(.n > 9)', self::DOC));
    }

    public function testMalformedYamlIsAYamlSyntaxException(): void
    {
        $this->expectException(YamlSyntaxException::class);
        $this->expectExceptionMessageMatches('/^yaml: line 2/');

        Yq::evaluate('.', "a: [1, 2\nb: : :\n");
    }

    public function testMalformedInputInAnotherFormatIsAFormatException(): void
    {
        $this->expectException(FormatException::class);

        Yq::evaluate('.', '{"a": ', input: FormatEnum::Json);
    }

    public function testASyntaxErrorInTheExpressionIsReported(): void
    {
        $this->expectException(ExpressionSyntaxException::class);

        Yq::evaluate('.a |', self::DOC);
    }

    public function testAnEvaluationErrorIsReported(): void
    {
        $this->expectException(EvaluationException::class);

        Yq::evaluate('.items | .a = (1 | error)', self::DOC);
    }

    public function testFileAndEnvOperatorsAreOffByDefault(): void
    {
        $this->expectException(EvaluationException::class);

        Yq::evaluate('load("/etc/hostname")', 'a: 1');
    }

    public function testEnvOperatorsCanBeEnabled(): void
    {
        putenv('PHPXQ_YQ_TEST=hello');

        try {
            self::assertSame("hello\n", Yq::evaluate('env("PHPXQ_YQ_TEST")', 'a: 1', allowEnv: true));
        } finally {
            putenv('PHPXQ_YQ_TEST');
        }
    }
}
