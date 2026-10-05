<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `@base32` and `@base32d` on texts long enough that the bit buffer wraps many times (RFC 4648 encodings).
 *
 * @internal
 */
final class FormatFunctionsBase32Test extends TestCase
{
    #[DataProvider('texts')]
    public function testEncode(string $text, string $encoded): void
    {
        self::assertSame($encoded, Harness::call('format', $text, ['base32']));
    }

    #[DataProvider('texts')]
    public function testDecode(string $text, string $encoded): void
    {
        self::assertSame($text, Harness::call('format', $encoded, ['base32d']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'a pangram' => ['The quick brown fox jumps over the lazy dog', 'KRUGKIDROVUWG2ZAMJZG653OEBTG66BANJ2W24DTEBXXMZLSEB2GQZJANRQXU6JAMRXWO==='];
        yield 'accented text' => ['héllo wörld ✓ ünïcödé ✓ ✓ ✓ ✓', 'NDB2S3DMN4QHPQ5WOJWGIIHCTSJSBQ54N3B26Y6DWZSMHKJA4KOJGIHCTSJSBYU4SMQOFHET'];
        yield 'euro signs' => ['€€€€€€€€€€€€', '4KBKZYUCVTRIFLHCQKWOFAVM4KBKZYUCVTRIFLHCQKWOFAVM4KBKZYUCVQ======'];
        yield 'tildes' => ['~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', 'PZ7H47T6PZ7H47T6PZ7H47T6PZ7H47T6PZ7H47T6PZ7H47T6'];
        yield 'letters' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 'PJ5HU6T2PJ5HU6T2PJ5HU6T2PJ5HU6T2PJ5HU6T2PJ5HU6T2'];
        yield 'control characters' => ["\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f", 'AEBAGBAFAYDQQCIKBMGA2DQP'];
    }
}
