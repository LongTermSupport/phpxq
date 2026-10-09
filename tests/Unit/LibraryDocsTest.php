<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Executes every ```php block of docs/LIBRARY.md against this checkout and compares its standard output with
 * the ```text block that follows it, so the documented examples cannot drift from the code.
 *
 * @internal
 */
final class LibraryDocsTest extends TestCase
{
    private const string DOCUMENT = __DIR__ . '/../../docs/LIBRARY.md';

    private const string AUTOLOAD_LINE = "require __DIR__ . '/vendor/autoload.php';";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snippets(): iterable
    {
        $markdown = (string)file_get_contents(self::DOCUMENT);
        $matched  = preg_match_all('/```php\n(.*?)```\n(?:\n```text\n(.*?)```\n)?/s', $markdown, $matches, \PREG_SET_ORDER);

        self::assertGreaterThan(0, $matched);

        foreach ($matches as $index => $match) {
            yield 'snippet ' . ($index + 1) => [$match[1], \array_key_exists(2, $match) ? $match[2] : ''];
        }
    }

    #[DataProvider('snippets')]
    public function testTheSnippetPrintsWhatTheDocumentSays(string $code, string $expected): void
    {
        self::assertStringContainsString(self::AUTOLOAD_LINE, $code);

        $autoload = (string)realpath(__DIR__ . '/../../vendor/autoload.php');
        $script   = tempnam(sys_get_temp_dir(), 'libdoc');
        self::assertNotFalse($script);

        file_put_contents($script, str_replace(self::AUTOLOAD_LINE, "require '" . $autoload . "';", $code));

        try {
            $process = new Process([\PHP_BINARY, '-d', 'display_errors=stderr', $script]);
            $process->run();
        } finally {
            unlink($script);
        }

        self::assertSame('', $process->getErrorOutput());
        self::assertSame(0, $process->getExitCode());
        self::assertSame($expected, $process->getOutput());
    }

    public function testTheDocumentNamesEveryClassItCallsPublic(): void
    {
        $markdown = (string)file_get_contents(self::DOCUMENT);

        foreach ([\LTS\PhpXq\Jq\Jq::class, \LTS\PhpXq\Yq\Yq::class, \LTS\PhpXq\Json\JsonDecoder::class, \LTS\PhpXq\Json\JsonEncoder::class] as $class) {
            self::assertStringContainsString('`' . $class . '`', $markdown);
            self::assertTrue(class_exists($class));
        }
    }
}
