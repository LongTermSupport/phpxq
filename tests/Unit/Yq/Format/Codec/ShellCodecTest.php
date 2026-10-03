<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\ShellEncoder;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ShellCodecTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEncode(string $yaml, string $expected, ?FormatOptions $options = null): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new ShellEncoder()->encode($document, $options ?? new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function cases(): iterable
    {
        yield 'documented example' => [
            "# comment\nname: Mike Wazowski\neyes:\n  color: turquoise\n  number: 1\nfriends:\n  - James P. Sullivan\n  - Celia Mae\n",
            "name='Mike Wazowski'\neyes_color=turquoise\neyes_number=1\nfriends_0='James P. Sullivan'\nfriends_1='Celia Mae'\n",
        ];

        yield 'illegal key characters' => [
            "ascii_=_symbols: replaced\n\"ascii_\\t_controls\": dropped\nnonascii_א_characters: dropped\neffort_expeñded_tò_ÀÉÎ: ok\n",
            "ascii___symbols=replaced\nascii__controls=dropped\nnonascii__characters=dropped\neffort_expended_to_AEI=ok\n",
        ];

        yield 'empty values arrays and maps' => ["empty:\n  value:\n  array: []\n  map: {}\n", "empty_value=\n"];

        yield 'single quotes' => ["name: Miles O'Brien\n", "name='Miles O'\"'\"'Brien'\n"];

        yield 'custom separator' => ["a:\n  b:\n    c: d\n", "a__b__c=d\n", new FormatOptions(shellKeySeparator: '__')];

        yield 'empty string is quoted' => ["a: ''\n", "a=''\n"];

        yield 'aliases and merges' => ["base: &b {x: 1}\nm:\n  <<: *b\n  y: 2\n", "base_x=1\nm_x=1\nm_y=2\n"];

        yield 'bare scalar' => ["hello world\n", "'hello world'\n"];
    }

    public function testFormat(): void
    {
        self::assertSame(Format::Shell, new ShellEncoder()->format());
    }
}
