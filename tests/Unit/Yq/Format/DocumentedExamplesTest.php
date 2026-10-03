<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the documented upstream conversion examples (usage pages for the data formats) that need nothing
 * but a decoder and an encoder: the expression is `.` or absent, so the decoded nodes go straight to the
 * encoder. The cases come from the vendored conformance fixtures.
 *
 * @internal
 */
final class DocumentedExamplesTest extends TestCase
{
    private const array NATIVE_FORMATS = [
        'usage/xml.md'  => Format::Xml,
        'usage/toml.md' => Format::Toml,
        'usage/hcl.md'  => Format::Hcl,
    ];

    /** Cases whose expected text in the fixture is damaged by the documentation extraction. */
    private const array DAMAGED = [
        'usage/base64.md: Encode base64: string',
        'usage/base64url.md: Encode base64url: string',
    ];

    #[DataProvider('examples')]
    public function testExample(string $source, string $input, string $expected, string $flagText): void
    {
        $flags  = json_decode($flagText, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($flags);

        [$inFormat, $outFormat, $options] = self::configure($source, array_values(array_map(strval(...), $flags)));

        $registry = new FormatRegistry();
        $decoder  = $registry->decoder($inFormat);
        $encoder  = $registry->encoder($outFormat);

        $out   = '';
        $index = 0;
        foreach ($decoder->decode($input, $options) as $document) {
            self::assertInstanceOf(Node::class, $document);
            $out .= $encoder->encode($document, $options, $index);
            ++$index;
        }

        self::assertSame($expected, $out);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function examples(): iterable
    {
        $json = file_get_contents(\dirname(__DIR__, 3) . '/Conformance/Yq/fixtures/cases.json');
        self::assertIsString($json);
        $cases = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);

        foreach ($cases as $number => $case) {
            self::assertIsArray($case);
            $name       = $case['name'] ?? '';
            $source     = $case['source'] ?? '';
            $expression = $case['expression'] ?? '';
            $command    = $case['command'] ?? null;
            $input      = $case['input'] ?? '';
            $expected   = $case['expected'] ?? '';
            $flags      = $case['flags'] ?? [];
            if (!\is_string($name) || !\is_string($source) || !\is_string($expression) || !\is_string($input) || !\is_string($expected) || !\is_array($flags)) {
                continue;
            }

            if (null !== $command || !str_starts_with($source, 'usage/') || !\in_array($expression, ['', '.'], true) || \in_array($name, self::DAMAGED, true)) {
                continue;
            }

            if (!\in_array($source, ['usage/base64.md', 'usage/base64url.md', 'usage/convert.md', 'usage/csv-tsv.md', 'usage/hcl.md', 'usage/kyaml.md', 'usage/lua.md', 'usage/properties.md', 'usage/shellvariables.md', 'usage/toml.md', 'usage/xml.md'], true)) {
                continue;
            }

            yield $number . ' ' . $name => [$source, $input, $expected, json_encode($flags, \JSON_THROW_ON_ERROR)];
        }
    }

    /**
     * @param list<string> $flags
     *
     * @return array{Format, Format, FormatOptions}
     */
    private static function configure(string $source, array $flags): array
    {
        $input  = null;
        $output = null;
        $values = [];
        $merged = [];
        for ($i = 0; $i < \count($flags); ++$i) {
            if (\in_array($flags[$i], ['-o', '-p', '-I'], true) && isset($flags[$i + 1])) {
                $merged[] = $flags[$i] . '=' . $flags[$i + 1];
                ++$i;
            } else {
                $merged[] = $flags[$i];
            }
        }

        foreach ($merged as $flag) {
            if (1 === preg_match('/^(?:-p|--input-format)[= ](.*)$/s', $flag, $m)) {
                $input = Format::fromName($m[1]);
            } elseif (1 === preg_match('/^(?:-o|--output-format)[= ]?(.*)$/s', $flag, $m)) {
                $output = Format::fromName($m[1]);
            } elseif (1 === preg_match('/^(?:-I|--indent)[= ]?(\d+)$/', $flag, $m)) {
                $values['indent'] = (int) $m[1];
            } elseif (1 === preg_match('/^--([a-zA-Z-]+)=(.*)$/s', $flag, $m)) {
                $values[$m[1]] = $m[2];
            } elseif (1 === preg_match('/^--([a-zA-Z-]+)$/', $flag, $m)) {
                $values[$m[1]] = 'true';
            }
        }

        $native = self::NATIVE_FORMATS[$source] ?? Format::Yaml;
        if (null === $input && null === $output) {
            $input  = $native;
            $output = $native;
        } else {
            $output ??= Format::Yaml;
            $input ??= $output === $native ? Format::Yaml : $native;
        }

        $truthy = static fn (string $key, bool $default): bool => isset($values[$key]) ? !\in_array($values[$key], ['false', 'f', '0'], true) : $default;

        $options = new FormatOptions(
            indent: isset($values['indent']) && \is_int($values['indent']) ? $values['indent'] : 2,
            unwrapScalar: $truthy('unwrapScalar', true),
            csvAutoParse: $truthy('csv-auto-parse', true),
            propertiesSeparator: \is_string($values['properties-separator'] ?? null) ? $values['properties-separator'] : ' = ',
            propertiesArrayBrackets: $truthy('properties-array-brackets', false),
            xmlSkipProcInst: $truthy('xml-skip-proc-inst', false),
            xmlSkipDirectives: $truthy('xml-skip-directives', false),
            xmlKeepNamespace: $truthy('xml-keep-namespace', true),
            xmlRawToken: $truthy('xml-raw-token', true),
            xmlStrictMode: $truthy('xml-strict-mode', false),
            shellKeySeparator: \is_string($values['shell-key-separator'] ?? null) ? $values['shell-key-separator'] : '_',
            luaUnquoted: $truthy('lua-unquoted', false),
            luaGlobals: $truthy('lua-globals', false),
        );

        return [$input, $output, $options];
    }
}
