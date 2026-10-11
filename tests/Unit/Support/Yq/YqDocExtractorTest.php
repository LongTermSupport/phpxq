<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Yq;

use LTS\PhpXq\Tests\Support\Yq\YqDocExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YqDocExtractorTest extends TestCase
{
    public function testExtractsGivenThenOutputSection(): void
    {
        $markdown = <<<'MD'
            # Add

            Intro prose.

            ## Concatenate arrays
            Given a sample.yml file of:
            ```yaml
            a: [1]
            b: [2]
            ```
            then
            ```bash
            yq '.a + .b' sample.yml
            ```
            will output
            ```yaml
            - 1
            - 2
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'operators/add.md');

        self::assertSame([], $result->skipped);
        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertSame('operators/add.md', $case->source);
        self::assertSame('Concatenate arrays', $case->heading);
        self::assertSame('operators/add.md: Concatenate arrays', $case->name);
        self::assertNull($case->command);
        self::assertSame([], $case->flags);
        self::assertSame('.a + .b', $case->expression);
        self::assertSame("a: [1]\nb: [2]\n", $case->input);
        self::assertSame("- 1\n- 2\n", $case->expected);
    }

    public function testNullInputWithFlagsAndSubcommand(): void
    {
        $markdown = <<<'MD'
            ## Collect
            Running
            ```bash
            yq eval-all --null-input -o=json '["cat"]'
            ```
            will output
            ```json
            ["cat"]
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'operators/collect.md');

        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertSame('eval-all', $case->command);
        self::assertSame(['--null-input', '-o=json'], $case->flags);
        self::assertSame('["cat"]', $case->expression);
        self::assertSame('', $case->input);
        self::assertSame("[\"cat\"]\n", $case->expected);
    }

    public function testFlagTakingSeparateValueIsKeptTogether(): void
    {
        $markdown = <<<'MD'
            ## Sep
            Running
            ```bash
            yq -n -o json '{}'
            ```
            will output
            ```json
            {}
            ```
            MD;

        $case = new YqDocExtractor()->extract($markdown, 'a.md')->cases[0];

        self::assertSame(['-n', '-o', 'json'], $case->flags);
        self::assertSame('{}', $case->expression);
    }

    public function testSeveralCommandsInOneSectionGetDistinctNames(): void
    {
        $markdown = <<<'MD'
            ## Twice
            Given a sample.yml file of:
            ```yaml
            a: 1
            ```
            then
            ```bash
            yq '.a' sample.yml
            ```
            will output
            ```yaml
            1
            ```
            then
            ```bash
            yq '.a + 1' sample.yml
            ```
            will output
            ```yaml
            2
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'a.md');

        self::assertCount(2, $result->cases);
        self::assertSame('a.md: Twice', $result->cases[0]->name);
        self::assertSame('a.md: Twice #2', $result->cases[1]->name);
        self::assertSame("a: 1\n", $result->cases[1]->input);
    }

    public function testInputPersistsOnlyWithinItsSection(): void
    {
        $markdown = <<<'MD'
            ## One
            Given a sample.yml file of:
            ```yaml
            a: 1
            ```
            then
            ```bash
            yq '.a' sample.yml
            ```
            will output
            ```yaml
            1
            ```

            ## Two
            then
            ```bash
            yq '.a' sample.yml
            ```
            will output
            ```yaml
            1
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'a.md');

        self::assertCount(1, $result->cases);
        self::assertCount(1, $result->skipped);
        self::assertSame('Two', $result->skipped[0]->heading);
        self::assertStringContainsString('sample.yml', $result->skipped[0]->reason);
    }

    public function testSkipsAreRecordedWithReasons(): void
    {
        $markdown = <<<'MD'
            ## Env
            then
            ```bash
            myenv="cat" yq -n '.a = env(myenv)'
            ```
            will output
            ```yaml
            a: cat
            ```

            ## Piped
            then
            ```bash
            cat x.yml | yq '.'
            ```
            will output
            ```yaml
            a: 1
            ```

            ## InPlace
            Given a sample.yml file of:
            ```yaml
            a: 1
            ```
            then
            ```bash
            yq -i '.a = 2' sample.yml
            ```
            will output
            ```yaml
            a: 2
            ```

            ## NoOutput
            then
            ```bash
            yq -n '.a'
            ```

            ## Error
            then
            ```bash
            yq -n 'error("x")'
            ```
            will output
            ```bash
            Error: x
            ```

            ## TwoFiles
            Given a sample.yml file of:
            ```yaml
            a: 1
            ```
            And another sample another.yml file of:
            ```yaml
            b: 2
            ```
            then
            ```bash
            yq '. *  load("another.yml")' sample.yml
            ```
            will output
            ```yaml
            a: 1
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'a.md');

        self::assertSame([], $result->cases);
        $reasons = [];
        foreach ($result->skipped as $skip) {
            $reasons[$skip->heading] = $skip->reason;
        }

        self::assertSame(['Env', 'Piped', 'InPlace', 'NoOutput', 'Error', 'TwoFiles'], array_keys($reasons));
        self::assertStringContainsString('environment', $reasons['Env']);
        self::assertStringContainsString('shell', $reasons['Piped']);
        self::assertStringContainsString('in-place', $reasons['InPlace']);
        self::assertStringContainsString('output', $reasons['NoOutput']);
        self::assertStringContainsString('error', $reasons['Error']);
        self::assertStringContainsString('files', $reasons['TwoFiles']);
        self::assertSame("yq -n 'error(\"x\")'", $result->skipped[4]->snippet);
    }

    public function testRandomOutputExamplesAreSkippedWithAReason(): void
    {
        $markdown = <<<'MD'
            ## Shuffle array
            Given a sample.yml file of:
            ```yaml
            - 1
            - 2
            - 3
            ```
            then
            ```bash
            yq 'shuffle' sample.yml
            ```
            will output
            ```yaml
            - 3
            - 1
            - 2
            ```

            ## Shuffled is just a heading
            Given a sample.yml file of:
            ```yaml
            a: 1
            ```
            then
            ```bash
            yq '.a' sample.yml
            ```
            will output
            ```yaml
            1
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'operators/shuffle.md');

        self::assertCount(1, $result->cases);
        self::assertSame('Shuffled is just a heading', $result->cases[0]->heading);
        self::assertCount(1, $result->skipped);
        self::assertSame('Shuffle array', $result->skipped[0]->heading);
        self::assertSame('documented output is random (shuffle)', $result->skipped[0]->reason);
    }

    public function testNonYqBashBlocksAreSkippedNotDropped(): void
    {
        $markdown = <<<'MD'
            ## Other
            ```bash
            diff a.yml b.yml
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'a.md');

        self::assertSame([], $result->cases);
        self::assertCount(1, $result->skipped);
        self::assertStringContainsString('not a yq', $result->skipped[0]->reason);
    }

    public function testCasesSerialiseToStableArrays(): void
    {
        $markdown = <<<'MD'
            ## Simple
            Running
            ```bash
            yq -n '1'
            ```
            will output
            ```yaml
            1
            ```
            MD;

        $result = new YqDocExtractor()->extract($markdown, 'a.md');

        self::assertSame(
            [
                'name'       => 'a.md: Simple',
                'source'     => 'a.md',
                'heading'    => 'Simple',
                'command'    => null,
                'flags'      => ['-n'],
                'expression' => '1',
                'input'      => '',
                'expected'   => "1\n",
            ],
            $result->cases[0]->toArray(),
        );
    }
}
