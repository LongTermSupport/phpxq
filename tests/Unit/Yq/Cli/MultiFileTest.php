<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\YqApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End to end (real parser, evaluator and emitter) behaviour of the reference over several files and
 * several documents. The single-file conformance cases cannot see these.
 *
 * Placeholders in arguments and expected output: {a} {b} {m} {empty} are the scratch file paths.
 *
 * @internal
 */
final class MultiFileTest extends TestCase
{
    private const string MERGED = "a: 1\nb:\n  - y\nc: 2\n";

    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/phpxq-multi-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o755, true)) {
            throw new RuntimeException('could not create ' . $directory);
        }

        $this->directory = $directory;
        file_put_contents($directory . '/a.yaml', "a: 1\nb:\n  - x\n");
        file_put_contents($directory . '/b.yaml', "c: 2\nb:\n  - y\n");
        file_put_contents($directory . '/m.yaml', "x: 1\n---\nx: 2\n");
        file_put_contents($directory . '/empty.yaml', '');
        file_put_contents($directory . '/c.yaml', "a: 1 # one\nb: 2\n");
        file_put_contents($directory . '/fm.md', "---\nfm: 1\n---\nbody text\n");
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->directory);
        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                unlink($this->directory . '/' . $entry);
            }
        }

        rmdir($this->directory);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('documentedExamples')]
    #[DataProvider('indexExamples')]
    public function testOutput(array $args, string $expected): void
    {
        [$code, $out, $err] = $this->yq($args);

        self::assertSame('', $err);
        self::assertSame(0, $code);
        self::assertSame($this->fill($expected), $out);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function documentedExamples(): iterable
    {
        yield 'merge two files by fileIndex' => [['ea', 'select(fileIndex == 0) * select(fileIndex == 1)', '{a}', '{b}'], self::MERGED];

        yield 'merge two files by fi' => [['ea', 'select(fi == 0) * select(fi == 1)', '{a}', '{b}'], self::MERGED];

        yield 'add two files by fileIndex' => [['ea', 'select(fileIndex == 0) + select(fileIndex == 1)', '{a}', '{b}'], self::MERGED];

        yield 'merge with the file indexes swapped' => [['ea', 'select(fileIndex == 1) * select(fileIndex == 0)', '{a}', '{b}'], "c: 2\nb:\n  - x\na: 1\n"];

        yield 'merge skipping an empty file' => [['ea', 'select(fi == 0) * select(fi == 2)', '{a}', '{empty}', '{b}'], self::MERGED];

        yield 'ireduce merges every document once' => [['ea', '. as $item ireduce ({}; . * $item)', '{a}', '{b}'], self::MERGED];

        yield 'ireduce counts the documents' => [['ea', '. as $item ireduce (0; . + 1)', '{a}', '{b}'], "2\n"];

        yield 'ireduce over the documents of one file' => [['ea', '. as $i ireduce ([]; . + [$i.x])', '{m}'], "- 1\n- 2\n"];

        yield 'collect gathers every document' => [['ea', '[.]', '{a}', '{b}'], "- a: 1\n  b:\n    - x\n- c: 2\n  b:\n    - y\n"];

        yield 'collect then multiply' => [['ea', '[.] | .[0] * .[1]', '{a}', '{b}'], self::MERGED];

        yield 'documents of several files are all collected' => [['ea', '[.] | length', '{m}', '{a}'], "3\n"];

        yield 'load in null input mode' => [['ea', '-n', 'load("{a}") * load("{b}")'], self::MERGED];

        yield 'two dots strip comments' => [['ea', '.. comments=""', '{c}'], "a: 1\nb: 2\n"];

        yield 'three dots strip comments' => [['ea', '... comments=""', '{c}'], "a: 1\nb: 2\n"];
    }

    /**
     * Values computed from a document root carry no document or file index, so they print without
     * separators, as in the reference.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function indexExamples(): iterable
    {
        yield 'eval fileIndex and documentIndex per document' => [['e', '[fileIndex, documentIndex]', '-o=json', '-I=0', '{m}', '{a}'], "[0,0]\n[0,1]\n[1,0]\n"];

        yield 'eval-all fileIndex per document' => [['ea', 'fileIndex', '{m}', '{a}'], "0\n0\n1\n"];

        yield 'eval-all document index per document' => [['ea', 'documentIndex', '{m}', '{a}'], "0\n1\n0\n"];

        yield 'snake case aliases' => [['ea', '[file_index, document_index, fi, di]', '-o=json', '-I=0', '{a}'], "[0,0,0,0]\n"];

        yield 'eval-all selects one document of a file' => [['ea', 'select(di == 1)', '{m}'], "x: 2\n"];

        yield 'eval-all selects by file and document' => [['ea', 'select(fi == 0 and di == 1)', '{m}', '{a}'], "x: 2\n"];

        yield 'eval keeps each document separate' => [['e', '.x', '{m}'], "1\n---\n2\n"];

        yield 'eval-all of a multi-document file' => [['ea', '.x', '{m}'], "1\n---\n2\n"];

        yield 'filename per document' => [['ea', 'filename', '{a}', '{b}'], "{a}\n{b}\n"];

        yield 'eval filename per file' => [['e', 'filename', '{a}', '{b}'], "{a}\n{b}\n"];

        yield 'no document separators' => [['e', '-N', '.x', '{m}'], "1\n2\n"];
    }

    public function testASpecialReadableFileIsEmptyInput(): void
    {
        self::assertSame([0, '', ''], $this->yq(['.', '/dev/null']));
        self::assertSame([0, '', ''], $this->yq(['e', '.', '/dev/null']));
        self::assertSame([0, '', ''], $this->yq(['ea', '.', '/dev/null']));
    }

    public function testASpecialFileCanBeLoaded(): void
    {
        self::assertSame([0, "null\n", ''], $this->yq(['-n', 'load("/dev/null")']));
    }

    public function testStandardInputMixesWithFilesInTheGivenOrder(): void
    {
        self::assertSame([0, "1\n---\nnull\n", ''], $this->yq(['e', '.a', '{a}', '-'], "c: 2\n"));
        self::assertSame([0, "null\n---\n1\n", ''], $this->yq(['e', '.a', '-', '{a}'], "c: 2\n"));
        self::assertSame([0, self::MERGED, ''], $this->yq(['ea', 'select(fi == 0) * select(fi == 1)', '{a}', '-'], "c: 2\nb:\n  - y\n"));
        self::assertSame([0, "0\n1\n", ''], $this->yq(['ea', 'fi', '-', '{a}'], "c: 2\n"));
    }

    public function testStandardInputIsTheDefaultInput(): void
    {
        self::assertSame([0, "2\n", ''], $this->yq(['.c'], "c: 2\n"));
        self::assertSame([0, "2\n", ''], $this->yq(['.c', '-'], "c: 2\n"));
    }

    public function testFrontMatterIsExtractedAndProcessed(): void
    {
        self::assertSame([0, "1\n", ''], $this->yq(['e', '--front-matter=extract', '.fm', '{fm}']));
        self::assertSame([0, "---\nfm: 2\n---\nbody text\n", ''], $this->yq(['e', '--front-matter=process', '.fm = 2', '{fm}']));
    }

    public function testInPlaceUpdatesTheFile(): void
    {
        self::assertSame([0, '', ''], $this->yq(['-i', '.a = 9', '{a}']));
        self::assertSame("a: 9\nb:\n  - x\n", (string)file_get_contents($this->directory . '/a.yaml'));
    }

    public function testSplitWritesOneFilePerDocument(): void
    {
        $previous = getcwd();
        self::assertIsString($previous);
        chdir($this->directory);

        try {
            self::assertSame([0, '', ''], $this->yq(['ea', '-s', '.x', '{m}']));
        } finally {
            chdir($previous);
        }

        self::assertSame("x: 1\n", (string)file_get_contents($this->directory . '/1.yml'));
        self::assertFileExists($this->directory . '/2.yml');
        unlink($this->directory . '/1.yml');
        unlink($this->directory . '/2.yml');
    }

    public function testAnEmptyFileAmongFilesDoesNotDisturbTheFileIndexes(): void
    {
        [$code, $out] = $this->yq(['ea', 'select(fi == 2) | .c', '{a}', '{empty}', '{b}']);

        self::assertSame(0, $code);
        self::assertSame("2\n", $out);
    }

    public function testAMissingFileIsStillAnError(): void
    {
        [$code, , $err] = $this->yq(['.', '{a}', $this->directory . '/missing.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString('no such file or directory', $err);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function yq(array $args, string $stdin = ''): array
    {
        $in  = $this->stream($stdin);
        $out = $this->stream('');
        $err = $this->stream('');

        $code = new YqApplication()->run($in, $out, $err, ...array_map($this->fill(...), $args));

        return [$code, $this->contents($out), $this->contents($err)];
    }

    private function fill(string $text): string
    {
        $paths = [];
        foreach (['a', 'b', 'm', 'c'] as $name) {
            $paths['{' . $name . '}'] = $this->directory . '/' . $name . '.yaml';
        }

        $paths['{empty}'] = $this->directory . '/empty.yaml';
        $paths['{fm}']    = $this->directory . '/fm.md';

        return strtr($text, $paths);
    }

    /**
     * @return resource
     */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('no memory stream');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }
}
