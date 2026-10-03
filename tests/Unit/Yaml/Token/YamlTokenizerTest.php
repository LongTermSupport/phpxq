<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Token\Token;
use LTS\PhpXq\Yaml\Token\TokenType;
use LTS\PhpXq\Yaml\Token\YamlTokenizer;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YamlTokenizerTest extends TestCase
{
    public function testStreamIsFramedByStartAndEnd(): void
    {
        $tokens = $this->tokenize('');

        self::assertSame(TokenType::StreamStart, $tokens[0]->type);
        self::assertSame(TokenType::StreamEnd, $tokens[\count($tokens) - 1]->type);
    }

    public function testBlockMapping(): void
    {
        self::assertSame(
            ['StreamStart', 'BlockMappingStart', 'Key', 'Scalar:a', 'Value', 'Scalar:1', 'BlockEnd', 'StreamEnd'],
            $this->summary("a: 1\n"),
        );
    }

    public function testFlowAndEntries(): void
    {
        self::assertSame(
            ['StreamStart', 'FlowSequenceStart', 'Scalar:a', 'FlowEntry', 'FlowMappingStart', 'Key', 'Scalar:b', 'Value', 'Scalar:c', 'FlowMappingEnd', 'FlowSequenceEnd', 'StreamEnd'],
            $this->summary('[a, {b: c}]'),
        );
    }

    public function testAnchorsAliasesAndTags(): void
    {
        self::assertSame(
            ['StreamStart', 'BlockSequenceStart', 'BlockEntry', 'Anchor:x', 'Tag:!!str', 'Scalar:v', 'BlockEntry', 'Alias:x', 'BlockEntry', 'Tag:!', 'Scalar:a', 'BlockEntry', 'Tag:!<tag:e.com,2000:t>', 'Scalar:b', 'BlockEnd', 'StreamEnd'],
            $this->summary("- &x !!str v\n- *x\n- ! a\n- !<tag:e.com,2000:t> b\n"),
        );
    }

    public function testDirectivesAndDocumentMarkers(): void
    {
        self::assertSame(
            ['StreamStart', 'Directive:YAML 1.1', 'Directive:TAG !e! tag:e.com,2000:', 'DocumentStart', 'Scalar:a', 'DocumentEnd', 'StreamEnd'],
            $this->summary("%YAML 1.1\n%TAG !e! tag:e.com,2000:\n---\na\n...\n"),
        );
    }

    public function testScalarStyles(): void
    {
        $scalars = array_values(array_filter($this->tokenize("- a\n- 'b'\n- \"c\"\n- |\n  d\n- >\n  e\n"), static fn (Token $t): bool => TokenType::Scalar === $t->type));

        self::assertSame(
            [NodeStyle::Default, NodeStyle::SingleQuoted, NodeStyle::DoubleQuoted, NodeStyle::Literal, NodeStyle::Folded],
            array_map(static fn (Token $t): NodeStyle => $t->style, $scalars),
        );
    }

    public function testPositionsAreOneBased(): void
    {
        $scalar = $this->tokenize("x: 1\ny: 22\n")[7];

        self::assertSame(TokenType::Scalar, $scalar->type);
        self::assertSame('y', $scalar->value);
        self::assertSame([2, 1], [$scalar->line, $scalar->column]);
    }

    public function testCommentsAppearAtTheirSourcePosition(): void
    {
        self::assertSame(
            ['StreamStart', 'Comment:# head', 'BlockMappingStart', 'Key', 'Scalar:a', 'Value', 'Scalar:1', 'Comment:# line', 'Comment:# foot', 'Key', 'Scalar:b', 'Value', 'Scalar:2', 'BlockEnd', 'StreamEnd'],
            $this->summary("# head\na: 1 # line\n# foot\n\nb: 2\n"),
        );
    }

    public function testMultiLineCommentBlockIsOneToken(): void
    {
        $comments = array_values(array_filter($this->tokenize("# a\n# b\n\n# c\nk: v\n"), static fn (Token $t): bool => TokenType::Comment === $t->type));

        self::assertSame(["# a\n# b\n\n# c"], array_map(static fn (Token $t): string => $t->value, $comments));
        self::assertSame([1, 1], [$comments[0]->line, $comments[0]->column]);
    }

    public function testSyntaxErrorsPropagate(): void
    {
        $this->expectException(YamlSyntaxException::class);
        $this->tokenize("a: 'x");
    }

    /**
     * @return list<Token>
     */
    private function tokenize(string $yaml): array
    {
        return iterator_to_array(new YamlTokenizer()->tokenize($yaml), false);
    }

    /**
     * @return list<string>
     */
    private function summary(string $yaml): array
    {
        return array_map(static fn (Token $t): string => $t->type->name . ('' === $t->value ? '' : ':' . $t->value), $this->tokenize($yaml));
    }
}
