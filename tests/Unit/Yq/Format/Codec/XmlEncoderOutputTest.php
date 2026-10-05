<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\XmlEncoder;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class XmlEncoderOutputTest extends TestCase
{
    private const string CONTENT = '+content';

    private const string ATTRIBUTE_X = '+@x';

    private const string PRETTY_ROOT = "<a>\n  <b>1</b>\n</a>\n";

    private static function commented(Node $node, string $head = '', string $line = '', string $foot = ''): Node
    {
        $node->headComment = $head;
        $node->lineComment = $line;
        $node->footComment = $foot;

        return $node;
    }

    #[DataProvider('outputCases')]
    public function testOutput(Node $document, string $expected, ?FormatOptions $options = null): void
    {
        self::assertSame($expected, new XmlEncoder()->encode($document, $options ?? new FormatOptions(), 0));
    }

    /**
     * @return iterable<string, array{Node, string, 2?: FormatOptions}>
     */
    public static function outputCases(): iterable
    {
        $compact = new FormatOptions(indent: 0);

        yield 'comments around a scalar element value' => [
            Node::mapping([Node::scalar('a'), self::commented(Node::scalar('x'), head: '# h', line: '# l', foot: '# f')]),
            "<a><!-- h -->x<!-- l --></a><!-- f -->\n",
            $compact,
        ];

        yield 'foot comment on a mapping value' => [
            Node::mapping([Node::scalar('a'), self::commented(Node::mapping([Node::scalar('b'), Node::scalar('1')]), foot: '# mf')]),
            "<a><b>1</b></a><!-- mf -->\n",
            $compact,
        ];

        yield 'foot comment on a mapping value inside a mapping' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), self::commented(Node::mapping([Node::scalar('c'), Node::scalar('1')]), foot: '# mf')])]),
            "<a><b><c>1</c></b><!-- mf --></a>\n",
            $compact,
        ];

        yield 'comments on the content node' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::CONTENT), self::commented(Node::scalar('t'), head: '# h', line: '# l', foot: '# f')])]),
            "<a><!-- h l -->t<!-- f --></a>\n",
            $compact,
        ];

        yield 'foot comment on an attribute key' => [
            Node::mapping([Node::scalar('a'), Node::mapping([self::commented(Node::scalar(self::ATTRIBUTE_X), foot: '# af'), Node::scalar('1'), Node::scalar('b'), Node::scalar('2')])]),
            "<a x=\"1\"><!-- af --><b>2</b></a>\n",
            $compact,
        ];

        yield 'foot comment on a top level key' => [
            Node::mapping([self::commented(Node::scalar('a'), foot: '# kf'), Node::scalar('1')]),
            "<a>1</a><!-- kf -->\n",
            $compact,
        ];

        yield 'head and line comments on a top level key' => [
            Node::mapping([self::commented(Node::scalar('a'), head: '# kh', line: '# kl'), Node::scalar('1')]),
            "<!-- kh -->\n<!-- kl -->\n<a>1</a>\n",
            $compact,
        ];

        yield 'comment text with a trailing newline' => [
            Node::mapping([Node::scalar('a'), self::commented(Node::scalar('x'), head: "# one\n")]),
            "<a><!-- one -->x</a>\n",
            $compact,
        ];

        yield 'multi-line comment' => [
            Node::mapping([Node::scalar('a'), self::commented(Node::scalar('x'), head: "# one\n# two")]),
            "<a><!-- one\ntwo -->x</a>\n",
            $compact,
        ];

        yield 'empty comment lines are dropped' => [
            Node::mapping([Node::scalar('a'), self::commented(Node::scalar('x'), head: '#')]),
            "<a>x</a>\n",
            $compact,
        ];

        yield 'attributes after a child still become attributes' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), Node::scalar('1'), Node::scalar(self::ATTRIBUTE_X), Node::scalar('2')])]),
            "<a x=\"2\"><b>1</b></a>\n",
            $compact,
        ];

        yield 'several attributes keep their order' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::ATTRIBUTE_X), Node::scalar('1'), Node::scalar('+@y'), Node::scalar('2')])]),
            "<a x=\"1\" y=\"2\"></a>\n",
            $compact,
        ];

        yield 'attribute escapes' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('+@q'), Node::scalar("a&b<c>d\"e'f\tg\nh\ri", '!!str')])]),
            "<a q=\"a&amp;b&lt;c&gt;d&#34;e&#39;f&#x9;g&#xA;h&#xD;i\"></a>\n",
            $compact,
        ];

        yield 'text escapes' => [
            Node::mapping([Node::scalar('a'), Node::scalar("a&b<c>d\"e'f\tg\nh\ri", '!!str')]),
            "<a>a&amp;b&lt;c&gt;d&#34;e&#39;f&#x9;g\nh&#xD;i</a>\n",
            $compact,
        ];

        yield 'pretty print with the default indent' => [Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), Node::scalar('1')])]), self::PRETTY_ROOT];

        yield 'negative indent is compact' => [Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), Node::scalar('1')])]), "<a><b>1</b></a>\n", new FormatOptions(indent: -1)];

        yield 'nested sequence repeats the element' => [
            Node::mapping([Node::scalar('a'), Node::sequence([Node::sequence([Node::scalar('1'), Node::scalar('2')]), Node::scalar('3')])]),
            "<a>1</a><a>2</a><a>3</a>\n",
            $compact,
        ];
    }

    #[DataProvider('errorCases')]
    public function testErrors(Node $document, string $expectedMessage): void
    {
        try {
            new XmlEncoder()->encode($document, new FormatOptions(), 0);
        } catch (FormatException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{Node, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'sequence attribute' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::ATTRIBUTE_X), Node::sequence()])]),
            'xml: cannot use !!seq as attribute, only scalars are supported',
        ];

        yield 'mapping attribute' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::ATTRIBUTE_X), Node::mapping()])]),
            'xml: cannot use !!map as attribute, only scalars are supported',
        ];

        yield 'sequence content' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::CONTENT), Node::sequence()])]),
            'xml: +content must be a scalar',
        ];

        yield 'mapping content' => [
            Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar(self::CONTENT), Node::mapping()])]),
            'xml: +content must be a scalar',
        ];

        yield 'scalar inside the top level sequence' => [Node::sequence([Node::scalar('x')]), 'xml: the top level must be a map or an array of maps'];
    }
}
