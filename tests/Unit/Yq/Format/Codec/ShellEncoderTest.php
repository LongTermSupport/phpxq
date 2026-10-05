<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\ShellEncoder;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ShellEncoderTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEncode(string $yaml, string $expected, ?FormatOptions $options = null): void
    {
        self::assertSame($expected, $this->encodeYaml($yaml, $options ?? new FormatOptions()));
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function cases(): iterable
    {
        yield 'array nested two maps deep keeps every key part' => ["a:\n  b:\n    - x\n    - y\n", "a_b_0=x\na_b_1=y\n"];

        yield 'array inside an array inside a map' => ["a:\n  - - x\n    - y\n  - z\n", "a_0_0=x\na_0_1=y\na_1=z\n"];

        yield 'maps inside arrays inside maps' => ["a:\n  b:\n    - c: 1\n      d: 2\n", "a_b_0_c=1\na_b_0_d=2\n"];

        yield 'custom separator between nested parts' => ["a:\n  b:\n    - x\n", "a.b.0=x\n", new FormatOptions(shellKeySeparator: '.')];

        yield 'accented key parts are folded' => ["\u{E9}t\u{E9}:\n  caf\u{E9}: 1\n", "ete_cafe=1\n"];

        yield 'combining marks are removed' => ["e\u{301}a: 1\n", "ea=1\n"];

        yield 'punctuation in keys becomes underscores' => ["a-b:\n  c.d: 1\n", "a_b_c_d=1\n"];
    }

    #[DataProvider('foldingCases')]
    public function testEveryAccentedLetterFoldsToItsBase(string $accented, string $base): void
    {
        self::assertSame('k' . str_repeat($base, mb_strlen($accented)) . "=1\n", $this->encodeYaml('"k' . $accented . "\": 1\n", new FormatOptions()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foldingCases(): iterable
    {
        yield 'A' => ['ÀÁÂÃÄÅĀĂĄǍ', 'A'];

        yield 'a' => ['àáâãäåāăąǎ', 'a'];

        yield 'C' => ['ÇĆĈĊČ', 'C'];

        yield 'c' => ['çćĉċč', 'c'];

        yield 'D' => ['ĎĐ', 'D'];

        yield 'd' => ['ďđ', 'd'];

        yield 'E' => ['ÈÉÊËĒĔĖĘĚ', 'E'];

        yield 'e' => ['èéêëēĕėęě', 'e'];

        yield 'G' => ['ĜĞĠĢ', 'G'];

        yield 'g' => ['ĝğġģ', 'g'];

        yield 'H' => ['ĤĦ', 'H'];

        yield 'h' => ['ĥħ', 'h'];

        yield 'I' => ['ÌÍÎÏĨĪĬĮİǏ', 'I'];

        yield 'i' => ['ìíîïĩīĭįıǐ', 'i'];

        yield 'J' => ['Ĵ', 'J'];

        yield 'j' => ['ĵ', 'j'];

        yield 'K' => ['Ķ', 'K'];

        yield 'k' => ['ķ', 'k'];

        yield 'L' => ['ĹĻĽĿŁ', 'L'];

        yield 'l' => ['ĺļľŀł', 'l'];

        yield 'N' => ['ÑŃŅŇ', 'N'];

        yield 'n' => ['ñńņň', 'n'];

        yield 'O' => ['ÒÓÔÕÖØŌŎŐǑ', 'O'];

        yield 'o' => ['òóôõöøōŏőǒ', 'o'];

        yield 'R' => ['ŔŖŘ', 'R'];

        yield 'r' => ['ŕŗř', 'r'];

        yield 'S' => ['ŚŜŞŠ', 'S'];

        yield 's' => ['śŝşš', 's'];

        yield 'T' => ['ŢŤŦ', 'T'];

        yield 't' => ['ţťŧ', 't'];

        yield 'U' => ['ÙÚÛÜŨŪŬŮŰŲǓ', 'U'];

        yield 'u' => ['ùúûüũūŭůűųǔ', 'u'];

        yield 'W' => ['Ŵ', 'W'];

        yield 'w' => ['ŵ', 'w'];

        yield 'Y' => ['ÝŶŸ', 'Y'];

        yield 'y' => ['ýÿŷ', 'y'];

        yield 'Z' => ['ŹŻŽ', 'Z'];

        yield 'z' => ['źżž', 'z'];
    }

    public function testAllCombiningMarksAreDropped(): void
    {
        $marks = '';
        for ($code = 0x300; $code <= 0x36F; ++$code) {
            $marks .= mb_chr($code, 'UTF-8');
        }

        self::assertSame("ab=1\n", $this->encodeYaml('"a' . $marks . "b\": 1\n", new FormatOptions()));
    }

    private function encodeYaml(string $yaml, FormatOptions $options): string
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return new ShellEncoder()->encode($document, $options, 0);
        }

        self::fail('no document');
    }
}
