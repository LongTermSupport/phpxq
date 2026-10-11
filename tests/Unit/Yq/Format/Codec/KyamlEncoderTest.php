<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\KyamlEncoder;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class KyamlEncoderTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEncode(string $yaml, string $expected): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new KyamlEncoder()->encode($document, new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain string scalar' => ["cat\n", "\"cat\"\n"];

        yield 'nested collections' => [
            "a: [1, {b: 2}, []]\nc: {}\n'1': \"x\\ty\"\nnull: 1\nd: [[1]]\n",
            "{\n  a: [\n    1,\n    {\n      b: 2,\n    },\n    [],\n  ],\n  c: {},\n  \"1\": \"x\\ty\",\n  \"null\": 1,\n  d: [\n    [\n      1,\n    ],\n  ],\n}\n",
        ];

        yield 'scalar kinds' => ["a: 12\nb: True\nc: null\nd: \"true\"\ne: 1.5\n", "{\n  a: 12,\n  b: true,\n  c: null,\n  d: \"true\",\n  e: 1.5,\n}\n"];

        yield 'comments' => [
            "# leading\na: 1 # a line\n# head b\nb: 2\nc:\n  # head d\n  - d # d line\n  - e\n# trailing\n",
            "# leading\n{\n  a: 1, # a line\n  # head b\n  b: 2,\n  c: [\n    # head d\n    \"d\", # d line\n    \"e\",\n  ],\n  # trailing\n}\n",
        ];

        yield 'aliases and merges are expanded' => [
            "base: &b {a: x}\ncopy: *b\nm:\n  <<: *b\n  z: 1\n",
            "{\n  base: {\n    a: \"x\",\n  },\n  copy: {\n    a: \"x\",\n  },\n  m: {\n    a: \"x\",\n    z: 1,\n  },\n}\n",
        ];

        yield 'control characters' => ["a: \"\\u0001\\r\\b\\f\\\"\"\n", "{\n  a: \"\\u0001\\r\\b\\f\\\"\",\n}\n"];

        yield 'empty root collections' => ["[]\n", "[]\n"];
    }

    public function testDocumentComments(): void
    {
        $document              = Node::document(Node::mapping([Node::scalar('a'), Node::scalar('1')]));
        $document->headComment = '# top';
        $document->footComment = '# bottom';

        self::assertSame("# top\n{\n  a: 1,\n}\n# bottom\n", new KyamlEncoder()->encode($document, new FormatOptions(), 0));
    }
}
