<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Cli\SplitFileWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `--split-exp` takes each file name from the data, so a name may only be a plain path inside the current
 * directory: a stream wrapper (`php://filter/...`, `file:///...`, `data:...`) or a path that climbs out of the
 * directory (`../x`, an absolute path elsewhere) is refused before anything is opened or created.
 *
 * @internal
 */
#[CoversClass(SplitFileWriter::class)]
#[Medium]
final class SplitFileWriterTest extends TestCase
{
    private const string OUT = 'out.yml';

    private string $root;

    private string $work;

    private string $previous;

    protected function setUp(): void
    {
        $previous = getcwd();
        if (false === $previous) {
            throw new RuntimeException('no current directory');
        }

        $this->previous = $previous;
        $this->root     = sys_get_temp_dir() . '/phpxq-split-' . bin2hex(random_bytes(6));
        $this->work     = $this->root . '/work';
        if (!mkdir($this->work, 0o755, true)) {
            throw new RuntimeException('could not create ' . $this->work);
        }

        chdir($this->work);
    }

    protected function tearDown(): void
    {
        chdir($this->previous);
        $this->remove($this->root);
    }

    #[DataProvider('refusedNames')]
    public function testANameOutsideTheCurrentDirectoryIsRefused(string $name): void
    {
        $name = str_replace('{root}', $this->root, $name);

        [$code, $out, $err] = $this->split($name);

        self::assertSame([1, ''], [$code, $out]);
        self::assertStringStartsWith('Error: ', $err);
        self::assertStringContainsString('split file name', $err);
        self::assertSame(['work'], $this->entries($this->root));
        self::assertSame([], $this->entries($this->work));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'parent directory' => ['../escaped.yml'];
        yield 'climbing through a subdirectory' => ['sub/../../escaped.yml'];
        yield 'absolute path elsewhere' => ['{root}/escaped.yml'];
        yield 'file wrapper' => ['file://{root}/escaped.yml'];
        yield 'php filter wrapper' => ['php://filter/write=string.rot13/resource={root}/escaped.yml'];
        yield 'ftp wrapper' => ['ftp://example.invalid/escaped.yml'];
        yield 'data wrapper' => ['data:text/plain,escaped'];
        yield 'nul byte' => ["escaped\0.yml"];
    }

    #[DataProvider('acceptedNames')]
    public function testANameInsideTheCurrentDirectoryIsWritten(string $name, string $written): void
    {
        $name = str_replace('{work}', $this->work, $name);

        self::assertSame([0, '', ''], $this->split($name));
        self::assertSame($this->document($name), (string)file_get_contents($this->work . '/' . $written));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'plain name' => [self::OUT, self::OUT];
        yield 'subdirectory' => ['sub/dir/' . self::OUT, 'sub/dir/' . self::OUT];
        yield 'climbing back inside' => ['sub/../' . self::OUT, self::OUT];
        yield 'absolute path inside' => ['{work}/' . self::OUT, self::OUT];
        yield 'colon later in the name' => ['out:1.yml', 'out:1.yml'];
        yield 'wrapper-like name made plain by its dot segment' => ['./data:' . self::OUT, 'data:' . self::OUT];
        yield 'php wrapper-like name made plain by its dot segment' => ['./php://' . self::OUT, 'php:/' . self::OUT];
    }

    /**
     * A symlink inside the directory that leads out of it is followed by the file system, so the name is judged by
     * where it really ends up.
     */
    #[DataProvider('symlinkEscapes')]
    public function testASymlinkLeadingOutOfTheCurrentDirectoryIsRefused(string $link, string $target, string $name): void
    {
        symlink($this->root . $target, $this->work . '/' . $link);

        [$code, $out, $err] = $this->split($name);

        self::assertSame([1, ''], [$code, $out]);
        self::assertStringContainsString('split file name', $err);
        self::assertSame(['work'], $this->entries($this->root));
        self::assertSame([$link], $this->entries($this->work));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function symlinkEscapes(): iterable
    {
        yield 'directory link to the parent' => ['up', '', 'up/escaped.yml'];
        yield 'directory link, deeper name' => ['up', '', 'up/new/escaped.yml'];
        yield 'dangling file link' => [self::OUT, '/escaped.yml', self::OUT];
    }

    public function testASymlinkThatStaysInsideIsFollowed(): void
    {
        mkdir($this->work . '/real');
        symlink($this->work . '/real', $this->work . '/inner');

        self::assertSame([0, '', ''], $this->split('inner/' . self::OUT));
        self::assertFileExists($this->work . '/real/' . self::OUT);
    }

    /**
     * @return array{int, string, string}
     */
    private function split(string $name): array
    {
        $result = new CliRunner()->run(['yq', '-s', '.name', '.'], $this->document($name));

        return [$result->exitCode, $result->stdout, $result->stderr];
    }

    /**
     * The one-key document whose `name` is the split file name, written double-quoted so any byte survives.
     */
    private function document(string $name): string
    {
        return 'name: ' . json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $entries = scandir($directory);
        if (false === $entries) {
            throw new RuntimeException('could not list ' . $directory);
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach ($this->entries($path) as $entry) {
                $this->remove($path . '/' . $entry);
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
