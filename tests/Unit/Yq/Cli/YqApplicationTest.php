<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\YqApplication;
use LTS\PhpXq\Yq\Cli\YqApplicationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YqApplicationTest extends TestCase
{
    private CliHarness $cli;

    protected function setUp(): void
    {
        $this->cli = new CliHarness();
    }

    protected function tearDown(): void
    {
        $this->cli->cleanup();
    }

    public function testTheCycleCollectorStateIsRestoredAfterARun(): void
    {
        $before = gc_enabled();

        try {
            gc_enable();
            $this->cli->run(['--version']);
            self::assertTrue(gc_enabled());

            gc_disable();
            $this->cli->run(['--version']);
            self::assertFalse(gc_enabled());
        } finally {
            if ($before) {
                gc_enable();
            } else {
                gc_disable();
            }
        }
    }

    public function testVersionNamesTheReferenceRelease(): void
    {
        [$code, $out] = $this->cli->run(['--version']);

        self::assertSame(0, $code);
        self::assertSame('yq (https://github.com/mikefarah/yq/) version ' . YqApplication::REFERENCE_VERSION . "\n", $out);
    }

    public function testHelpListsCommandsAndFlags(): void
    {
        [$code, $out] = $this->cli->run(['--help']);

        self::assertSame(0, $code);
        self::assertStringContainsString("Usage:\n  yq [flags]\n  yq [command]", $out);
        self::assertStringContainsString('  eval-all    Loads _all_ yaml documents', $out);
        self::assertStringContainsString('-P, --prettyPrint', $out);
        self::assertStringNotContainsString('--tojson', $out);
    }

    public function testEvaluatesTheExpressionAgainstAFile(): void
    {
        $file = $this->cli->file('test.yml', "a: 123\nb: cat\n");

        self::assertSame([0, "123\n", ''], $this->cli->run(['.a', $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['e', '.a', $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['ea', '.a', $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['eval', '.a', $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['eval-all', '.a', $file]));
    }

    public function testAFileAsTheOnlyArgumentIsPrintedWithTheDefaultExpression(): void
    {
        $file = $this->cli->file('test.yml', "a: 123\n");

        self::assertSame([0, "a: 123\n", ''], $this->cli->run([$file]));
        self::assertSame([0, "a: 123\n", ''], $this->cli->run([$file, '-P']));
    }

    public function testStandardInputIsReadWhenNoFileIsGiven(): void
    {
        self::assertSame([0, "frog\n", ''], $this->cli->run(['.a'], "a: frog\n"));
        self::assertSame([0, "a: frog\n", ''], $this->cli->run([], "a: frog\n"));
        self::assertSame([0, "a: frog\n", ''], $this->cli->run(['e'], "a: frog\n"));
        self::assertSame([0, "a: frog\n", ''], $this->cli->run(['ea'], "a: frog\n"));
    }

    public function testADashReadsStandardInputBesideFiles(): void
    {
        $file = $this->cli->file('test2.yml', "a: 124\n");

        self::assertSame([0, "a: 123\n---\na: 124\n", ''], $this->cli->run(['-', $file], "a: 123\n"));
        self::assertSame([0, "a: 124\n---\na: 123\n", ''], $this->cli->run([$file, '-'], "a: 123\n"));
    }

    public function testFilesGivenMeansStandardInputIsIgnored(): void
    {
        $file = $this->cli->file('test.yml', "a: 124\n");

        self::assertSame([0, "124\n", ''], $this->cli->run(['.a', $file], "a: 123\n"));
    }

    public function testDocumentsAreSeparatedByDashes(): void
    {
        $file = $this->cli->file('test.yml', "---\nname: chart-name\n---\nname: thing\n");

        self::assertSame([0, "chart-name\n---\nthing\n", ''], $this->cli->run(['.name', $file]));
        self::assertSame([0, "chart-name\n---\nthing\n", ''], $this->cli->run(['ea', '.name', $file]));
    }

    public function testNoDocSuppressesSeparators(): void
    {
        $file = $this->cli->file('test.yml', "---\nname: chart-name\n---\nname: thing\n");

        self::assertSame([0, "chart-name\nthing\n", ''], $this->cli->run(['-N', '.name', $file]));
    }

    public function testSeveralResultsFromOneDocumentShareNoSeparator(): void
    {
        $file = $this->cli->file('test.yml', "- yay\n- wiz\n");

        self::assertSame([0, "yay\nwiz\n", ''], $this->cli->run(['.[]', $file]));
    }

    public function testFilesAreSeparatedByDashes(): void
    {
        $one = $this->cli->file('one.yml', "a: 0\n");
        $two = $this->cli->file('two.yml', "a: 1\n");

        self::assertSame([0, "0\n---\n1\n", ''], $this->cli->run(['.a', $one, $two]));
        self::assertSame([0, "0\n---\n1\n", ''], $this->cli->run(['ea', '.a', $one, $two]));
    }

    public function testTheHeaderIsKeptAheadOfTheFirstDocument(): void
    {
        $text = "# hi peeps\n# cool\n---\na: test\n---\nb: cool\n";
        $file = $this->cli->file('test.yml', $text);

        self::assertSame([0, $text, ''], $this->cli->run([$file]));
    }

    public function testTheHeaderKeepsBlankLines(): void
    {
        $text = "# hi peeps\n# cool\n\n\n---\na: test\n---\nb: cool\n";
        $file = $this->cli->file('test.yml', $text);

        self::assertSame([0, $text, ''], $this->cli->run([$file]));
    }

    public function testTheHeaderIsNotPrintedForADerivedValue(): void
    {
        $file = $this->cli->file('test.yml', "# hi peeps\n---\na: test\n");

        self::assertSame([0, "test\n", ''], $this->cli->run(['.a', $file]));
    }

    public function testHeaderMarkersAreDroppedWithNoDoc(): void
    {
        $file = $this->cli->file('test.yml', "---\n# hi peeps\na: test\n---\n# another\nb: sane\n");

        self::assertSame([0, "# hi peeps\na: test\n# another\nb: sane\n", ''], $this->cli->run(['--no-doc', $file]));
    }

    public function testEachFilesHeaderStaysWithItsDocument(): void
    {
        $one = $this->cli->file('one.yml', "# hi peeps\n# cool\na: test\n");
        $two = $this->cli->file('two.yml', "# this is another doc\nb: sane\n");

        self::assertSame(
            [0, "# hi peeps\n# cool\na: test\n---\n# this is another doc\nb: sane\n", ''],
            $this->cli->run([$one, $two]),
        );
    }

    public function testALeadingSeparatorInTheSecondFileIsNotDoubled(): void
    {
        $one = $this->cli->file('one.yml', "---\n# hi\na: test\n");
        $two = $this->cli->file('two.yml', "---\n# there\nb: sane\n");

        self::assertSame([0, "---\n# hi\na: test\n---\n# there\nb: sane\n", ''], $this->cli->run([$one, $two]));
    }

    public function testADirectiveDocumentIsPrintedWithItsFraming(): void
    {
        $file = $this->cli->file('test.yml', "%YAML 1.1\n---\nthis: should really work\n");

        self::assertSame([0, "%YAML 1.1\n---\nthis: should really work\n", ''], $this->cli->run([$file]));
    }

    public function testWithoutHeaderPreprocessingAFirstSeparatorIsLost(): void
    {
        $file = $this->cli->file('test.yml', "---\na: 1\n---\nb: 2\n");

        self::assertSame([0, "a: 1\n---\nb: 2\n", ''], $this->cli->run(['--header-preprocess=false', $file]));
        self::assertSame([0, "---\na: 1\n---\nb: 2\n", ''], $this->cli->run([$file]));
    }

    public function testEvalAllPrintsTheFirstFilesHeaderOnly(): void
    {
        $one = $this->cli->file('one.yml', "# top\n---\na: 1\n");
        $two = $this->cli->file('two.yml', "b: 2\n");

        self::assertSame([0, "# top\n---\na: 1\n---\nb: 2\n", ''], $this->cli->run(['ea', $one, $two]));
    }

    public function testStringInterpolationCanBeSwitchedOff(): void
    {
        self::assertSame([0, "Mike \\(3 + 4)\n", ''], $this->cli->run(['--string-interpolation=f', '-n', '"Mike \(3 + 4)"']));
        self::assertSame([0, "a\\(b)\n", ''], $this->cli->run(['--string-interpolation=f', '-n', '"a\\\(b)"']));
        self::assertSame(1, $this->cli->run(['-n', '"Mike \(3 + 4)"'])[0]);
    }

    public function testACommentOnlyFileIsPrintedAsIs(): void
    {
        $file = $this->cli->file('test.yml', "# comment\n");

        self::assertSame([0, "# comment\n", ''], $this->cli->run(['e', $file]));
        self::assertSame([0, "# comment\n", ''], $this->cli->run(['ea', $file]));
    }

    public function testACommentWithoutATrailingNewlineGainsOne(): void
    {
        $file = $this->cli->file('test.yml', '#comment');

        self::assertSame([0, "#comment\n", ''], $this->cli->run(['e', $file]));
    }

    public function testAnEmptyFilePrintsNothing(): void
    {
        $file = $this->cli->file('test.yml', '');

        self::assertSame([0, '', ''], $this->cli->run([$file]));
    }

    public function testAnEmptyFileStillGivesTheExpressionADocument(): void
    {
        $file = $this->cli->file('test.yml', '');
        $this->cli->run(['.a', $file]);

        self::assertCount(1, $this->cli->evaluator->contexts);
        self::assertCount(1, $this->cli->evaluator->contexts[0]->matches);
        self::assertSame('!!null', $this->cli->evaluator->contexts[0]->matches[0]->node->root()->tag);
    }

    public function testNullInputEvaluatesAgainstANullDocument(): void
    {
        self::assertSame([0, "null\n", ''], $this->cli->run(['-n', '.a']));
        self::assertSame([0, "null\n", ''], $this->cli->run(['e', '--null-input', '.a']));
        self::assertSame([0, "null\n", ''], $this->cli->run(['ea', '-n', '.a']));
    }

    public function testNullInputRejectsFiles(): void
    {
        self::assertSame([1, '', "Error: cannot pass files in when using null-input flag\n"], $this->cli->run(['e', '-n', '.a', 'test.yml']));
        self::assertSame([1, '', "Error: cannot pass files in when using null-input flag\n"], $this->cli->run(['ea', '-n', '.a', 'test.yml']));
    }

    public function testInPlaceNeedsAFile(): void
    {
        $message = "Error: write in place flag only applicable when giving an expression and at least one file\n";

        self::assertSame([1, '', $message], $this->cli->run(['e', '-i', '-n', '.a']));
        self::assertSame([1, '', $message], $this->cli->run(['ea', '-i', '-n', '.a']));
        self::assertSame([1, '', $message], $this->cli->run(['-i', '.a'], "a: 1\n"));
    }

    public function testInPlaceCannotSplit(): void
    {
        $message = "Error: write in place cannot be used with split file\n";

        self::assertSame([1, '', $message], $this->cli->run(['e', '-s', 'cat', '-i', '.a', 'test.yml']));
        self::assertSame([1, '', $message], $this->cli->run(['ea', '-s', 'cat', '-i', '.a', 'test.yml']));
    }

    public function testInPlaceRewritesTheFirstFileKeepingItsPermissions(): void
    {
        $file = $this->cli->file('test.yml', "a: 0\n");
        chmod($file, 0o640);
        $other = $this->cli->file('test2.yml', "a: 1\n");

        self::assertSame([0, '', ''], $this->cli->run(['-i', '.a', $file, $other]));

        self::assertSame("0\n---\n1\n", $this->cli->read('test.yml'));
        self::assertSame("a: 1\n", $this->cli->read('test2.yml'));
        clearstatcache();
        self::assertSame(0o640, fileperms($file) & 0o7777);
    }

    public function testInPlaceWithoutAnExpressionRewritesWithAllFiles(): void
    {
        $file  = $this->cli->file('test.yml', "a: 0\n");
        $other = $this->cli->file('test2.yml', "a: 1\n");

        self::assertSame([0, '', ''], $this->cli->run(['-i', $file, $other]));
        self::assertSame("a: 0\n---\na: 1\n", $this->cli->read('test.yml'));
    }

    public function testInPlaceLeavesTheFileAloneOnFailure(): void
    {
        $file = $this->cli->file('test.yml', "a: 0\n");

        [$code, , $err] = $this->cli->run(['-i', '.a.b.c.d | nope(', $file]);

        self::assertSame(1, $code);
        self::assertStringStartsWith('Error: ', $err);
        self::assertSame("a: 0\n", $this->cli->read('test.yml'));
    }

    public function testExitStatusFailsWhenNothingMatches(): void
    {
        $file = $this->cli->file('test.yml', "a: cat\n");

        self::assertSame([0, "null\n", ''], $this->cli->run(['e', '.z', $file]));
        self::assertSame([1, "null\n", "Error: no matches found\n"], $this->cli->run(['e', '-e', '.z', $file]));
        self::assertSame([1, "null\n", "Error: no matches found\n"], $this->cli->run(['-e', '.z', $file]));
        self::assertSame([0, "cat\n", ''], $this->cli->run(['-e', '.a', $file]));
    }

    public function testSplitWritesEachDocumentToAFileNamedByTheExpression(): void
    {
        $dir  = $this->cli->directory;
        $file = $this->cli->file('test.yml', "a: {$dir}/doc1\n--- \na: {$dir}/doc2\n");

        self::assertSame([0, '', ''], $this->runInDirectory($file, '-s', '.a'));

        self::assertSame("a: {$dir}/doc1\n", $this->cli->read('doc1.yml'));
        self::assertSame("---\na: {$dir}/doc2\n", $this->cli->read('doc2.yml'));
    }

    public function testSplitKeepsAnExistingExtensionAndCreatesDirectories(): void
    {
        $dir  = $this->cli->directory;
        $file = $this->cli->file('test.yml', "f: {$dir}/d1/d2/one.yaml\n---\nf: {$dir}/two\n");

        self::assertSame([0, '', ''], $this->runInDirectory('--no-doc', '-s', '.f', $file));

        self::assertSame("f: {$dir}/d1/d2/one.yaml\n", $this->cli->read('d1/d2/one.yaml'));
        self::assertSame("f: {$dir}/two\n", $this->cli->read('two.yml'));
    }

    public function testSplitExpressionCanComeFromAFile(): void
    {
        $dir   = $this->cli->directory;
        $file  = $this->cli->file('test.yml', "a: {$dir}/doc1\n");
        $split = $this->cli->file('split.txt', ".a\n");

        self::assertSame([0, '', ''], $this->runInDirectory($file, '--split-exp-file', $split));
        self::assertSame("a: {$dir}/doc1\n", $this->cli->read('doc1.yml'));
    }

    public function testSplitBindsTheResultIndex(): void
    {
        $file = $this->cli->file('test.yml', "a: x\n---\na: y\n");

        // The fake evaluator resolves $index to the counter node; its value is the file stem.
        self::assertSame([0, '', ''], $this->runInDirectory($file, '-s', '$index'));

        self::assertSame("a: x\n", $this->cli->read('0.yml'));
        self::assertSame("---\na: y\n", $this->cli->read('1.yml'));
    }

    public function testFrontMatterProcessKeepsTheRestOfTheFile(): void
    {
        $text = "---\na: apple\nb: cat\n---\nnot yaml\nc: at\n";
        $file = $this->cli->file('test.yml', $text);

        self::assertSame([0, '', ''], $this->cli->run(['e', '--front-matter=process', '.', $file, '-i']));
        self::assertSame($text, $this->cli->read('test.yml'));
    }

    public function testFrontMatterExtractDropsTheRestOfTheFile(): void
    {
        $file = $this->cli->file('test.yml', "a: apple\nb: cat\n---\nnot yaml\nc: at\n");

        self::assertSame([0, '', ''], $this->cli->run(['e', '--front-matter=extract', '.', $file, '-i']));
        self::assertSame("a: apple\nb: cat\n", $this->cli->read('test.yml'));
    }

    public function testFrontMatterProcessAppendsTheContentToStandardOutput(): void
    {
        $file = $this->cli->file('test.yml', "---\na: apple\n---\ntext\n");

        self::assertSame([0, "---\na: apple\n---\ntext\n", ''], $this->cli->run(['-f', 'process', '.', $file]));
    }

    public function testFrontMatterMustBeExtractOrProcess(): void
    {
        $file = $this->cli->file('test.yml', "a: 1\n");

        [$code, , $err] = $this->cli->run(['--front-matter=other', '.', $file]);

        self::assertSame(1, $code);
        self::assertStringStartsWith('Error: front-matter', $err);
    }

    public function testNulOutputEndsEveryValueWithNul(): void
    {
        $file = $this->cli->file('test.yml', "- yay\n- wiz\n");

        self::assertSame([0, "yay\0wiz\0", ''], $this->cli->run(['-0', '.[]', $file]));
    }

    public function testNulOutputRefusesAValueContainingNul(): void
    {
        $file = $this->cli->file('test.yml', "- yay\n- \"foo\\u0000bar\"\n- pow\n");

        [$code, $out, $err] = $this->cli->run(['-0', '.[]', $file]);

        self::assertSame(1, $code);
        self::assertSame("yay\0", $out);
        self::assertStringStartsWith("Error: Can't serialize value because it contains NUL char", $err);
    }

    public function testExpressionCanBeForcedAndMayNameAFile(): void
    {
        $file = $this->cli->file('test.yml', "xyz: 123\n");
        $this->cli->file('.xyz', '');

        self::assertSame([0, "123\n", ''], $this->cli->run(['--expression', '.xyz', $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['ea', '--expression', '.xyz', $file]));
    }

    public function testExpressionCanBeReadFromAFileWithDosLineEndings(): void
    {
        $file   = $this->cli->file('test.yml', "xyz: 123\n");
        $script = $this->cli->file('instructions.txt', ".xyz\r\n");

        self::assertSame([0, "123\n", ''], $this->cli->run(['--from-file', $script, $file]));
        self::assertSame([0, "123\n", ''], $this->cli->run(['ea', '--from-file', $script, $file]));
    }

    public function testAnExecutableScriptWithAShebangSuppliesTheExpression(): void
    {
        $dir    = $this->cli->directory;
        $script = $this->cli->file('test.yq', "#!./yq\n.a\n");
        chmod($script, 0o755);
        $file = $this->cli->file('test.yml', "a: apple\n");

        self::assertSame([0, "apple\n", ''], $this->cli->run([$script, $file]));
        self::assertDirectoryExists($dir);
    }

    public function testOutputFormatDispatchesToTheEncoder(): void
    {
        $file = $this->cli->file('test.yml', "a: 1\n");

        self::assertSame([0, "json:0:1\n", ''], $this->cli->run(['-o=json', '.a', $file]));
        self::assertSame([0, "json:0:1\n", ''], $this->cli->run(['-oj', '.a', $file]));
        self::assertSame([0, "json:0:1\n", ''], $this->cli->run(['-j', '.a', $file]));
        self::assertSame([0, "props:0:1\n", ''], $this->cli->run(['-o', 'props', '.a', $file]));
    }

    public function testEncoderIndexCountsResults(): void
    {
        $file = $this->cli->file('test.yml', "- a\n- b\n");

        self::assertSame([0, "csv:0:a\ncsv:1:b\n", ''], $this->cli->run(['-o=csv', '.[]', $file]));
    }

    public function testTheInputFormatFollowsTheFileExtensionAndSetsTheDefaultOutput(): void
    {
        $file = $this->cli->file('test.json', "{}\n");

        self::assertSame([0, "json:0:{}\n", ''], $this->cli->run([$file]));
        self::assertSame([0, "{}\n", ''], $this->cli->run([$file, '-oy']));
        self::assertSame([0, "{}\n", ''], $this->cli->run(['-p=json', $file]));
    }

    public function testAnExplicitInputFormatDefaultsToYamlOutput(): void
    {
        $file = $this->cli->file('test.properties', "mike.things = hello\n");

        self::assertSame([0, "props:0:mike.things = hello\n", ''], $this->cli->run([$file]));
        self::assertSame([0, "mike.things = hello\n", ''], $this->cli->run(['-p=props', '.', $file]));
    }

    public function testFormatFlagsReachTheCodecs(): void
    {
        $file = $this->cli->file('test.csv', "a;b\n");
        $this->cli->run(['-p=csv', '--csv-separator', ';', '--csv-auto-parse=f', '-I=4', $file]);

        $options = $this->cli->formats->seenOptions[0];
        self::assertSame(';', $options->csvSeparator);
        self::assertFalse($options->csvAutoParse);
        self::assertSame(4, $options->indent);
    }

    public function testUnknownFormatsAreRejected(): void
    {
        [$code, , $err] = $this->cli->run(['-p=nope', '.']);

        self::assertSame(1, $code);
        self::assertStringStartsWith("Error: unknown format 'nope' please use [", $err);
    }

    public function testUnknownFlagsPrintTheUsage(): void
    {
        [$code, $out, $err] = $this->cli->run(['--nope', '.']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith("Error: unknown flag: --nope\nUsage:\n  yq [flags]", $err);
    }

    public function testMissingFilesAreReported(): void
    {
        self::assertSame([1, '', "Error: open nope.yml: no such file or directory\n"], $this->cli->run(['.', 'nope.yml']));
    }

    public function testBadYamlNamesTheFile(): void
    {
        $file = $this->cli->file('bad.yml', "a: [1, 2\n");

        [$code, , $err] = $this->cli->run(['.', $file]);

        self::assertSame(1, $code);
        self::assertStringStartsWith("Error: bad file '{$file}': yaml: line ", $err);
    }

    public function testEvaluationErrorsAreReported(): void
    {
        $file = $this->cli->file('test.yml', "a: 1\n");

        [$code, , $err] = $this->cli->run(['$nope', $file]);

        self::assertSame(1, $code);
        self::assertSame("Error: unbound variable \$nope\n", $err);
    }

    public function testExpressionSyntaxErrorsAreReported(): void
    {
        $file = $this->cli->file('test.yml', "a: 1\n");

        [$code, , $err] = $this->cli->run(['.a |', $file]);

        self::assertSame(1, $code);
        self::assertStringStartsWith('Error: ', $err);
    }

    public function testCompletionPrintsAScript(): void
    {
        [$code, $out] = $this->cli->run(['completion', 'bash']);

        self::assertSame(0, $code);
        self::assertStringContainsString('__start_yq', $out);
        self::assertSame(1, $this->cli->run(['completion'])[0]);
        self::assertSame(1, $this->cli->run(['completion', 'nushell'])[0]);
    }

    public function testCompleteCommandListsSubCommands(): void
    {
        [$code, $out, $err] = $this->cli->run(['__complete', '']);

        self::assertSame(0, $code);
        self::assertStringContainsString("eval-all\tLoads _all_ yaml documents", $out);
        self::assertStringEndsWith(":4\n", $out);
        self::assertStringContainsString('Completion ended with directive: ShellCompDirectiveNoFileComp', $err);
    }

    public function testStatusCodesComeFromTheInterface(): void
    {
        self::assertSame(YqApplicationInterface::EXIT_OK, $this->cli->run(['--version'])[0]);
        self::assertSame(YqApplicationInterface::EXIT_ERROR, $this->cli->run(['--nope'])[0]);
    }

    /**
     * Runs from inside the scratch directory: `--split-exp` only writes files inside the current directory.
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runInDirectory(string ...$args): array
    {
        $previous = getcwd();
        self::assertIsString($previous);
        chdir($this->cli->directory);

        try {
            return $this->cli->run(array_values($args));
        } finally {
            chdir($previous);
        }
    }
}
