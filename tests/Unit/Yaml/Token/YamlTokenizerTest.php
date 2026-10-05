<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Token;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Token\Token;
use LTS\PhpXq\Yaml\Token\TokenTypeEnum;
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

        self::assertSame(TokenTypeEnum::StreamStart, $tokens[0]->type);
        self::assertSame(TokenTypeEnum::StreamEnd, $tokens[\count($tokens) - 1]->type);
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
        $scalars = array_values(array_filter($this->tokenize("- a\n- 'b'\n- \"c\"\n- |\n  d\n- >\n  e\n"), static fn (Token $t): bool => TokenTypeEnum::Scalar === $t->type));

        self::assertSame(
            [NodeStyleEnum::Default, NodeStyleEnum::SingleQuoted, NodeStyleEnum::DoubleQuoted, NodeStyleEnum::Literal, NodeStyleEnum::Folded],
            array_map(static fn (Token $t): NodeStyleEnum => $t->style, $scalars),
        );
    }

    public function testPositionsAreOneBased(): void
    {
        $scalar = $this->tokenize("x: 1\ny: 22\n")[7];

        self::assertSame(TokenTypeEnum::Scalar, $scalar->type);
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
        $comments = array_values(array_filter($this->tokenize("# a\n# b\n\n# c\nk: v\n"), static fn (Token $t): bool => TokenTypeEnum::Comment === $t->type));

        self::assertSame(["# a\n# b\n\n# c"], array_map(static fn (Token $t): string => $t->value, $comments));
        self::assertSame([1, 1], [$comments[0]->line, $comments[0]->column]);
    }

    public function testCommentsKeepTheirPlaceAmongTokensFarIntoTheText(): void
    {
        self::assertSame(
            ['StreamStart', 'BlockMappingStart', 'Key', 'Scalar:a', 'Value', 'Scalar:1', 'Key', 'Scalar:b', 'Value', 'Scalar:2', 'Key', 'Scalar:c', 'Value', 'Scalar:3', 'Comment:#', 'Key', 'Scalar:d', 'Value', 'Scalar:4', 'BlockEnd', 'StreamEnd'],
            $this->summary("a: 1\nb: 2\nc: 3\n#\nd: 4\n"),
        );
    }

    public function testCommentColumnsAreOneBased(): void
    {
        $comments = array_values(array_filter($this->tokenize("# head\na: 1 # line\n# foot\n\nb: 2\n"), static fn (Token $t): bool => TokenTypeEnum::Comment === $t->type));

        self::assertSame(
            [['# head', 1, 1], ['# line', 2, 6], ['# foot', 3, 1]],
            array_map(static fn (Token $t): array => [$t->value, $t->line, $t->column], $comments),
        );
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
