<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Conformance;

use Closure;
use LTS\PhpXq\Cli\FrontControllerInterface;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use LTS\PhpXq\Tests\Support\Conformance\YqConformanceSuite;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @internal
 */
final class YqConformanceSuiteTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yqcases');
        self::assertNotFalse($path);
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testBuildsCasesAndArguments(): void
    {
        $this->write(
            ['name' => 'one', 'command' => 'eval', 'flags' => ['-o', 'json'], 'expression' => '.a', 'input' => 'a: 1', 'expected' => "1\n"],
            ['name' => 'two', 'command' => null, 'flags' => [], 'expression' => null, 'input' => '', 'expected' => ''],
        );

        $suite = new YqConformanceSuite($this->file);
        $cases = [...$suite->cases()];

        self::assertSame('yq', $suite->name());
        self::assertSame(['one', 'two'], array_map(static fn (ConformanceCase $case): string => $case->id, $cases));

        $captured = [];
        $runner   = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out) use (&$captured): int {
            $captured[] = [$args, $stdin];
            fwrite($out, "1\n");

            return 0;
        }));

        self::assertNull($cases[0]->evaluate($runner));
        self::assertSame([['yq', 'eval', '-o', 'json', '.a'], 'a: 1'], $captured[0]);
    }

    public function testWrongStdoutFails(): void
    {
        $this->write(['name' => 'one', 'command' => null, 'flags' => [], 'expression' => '.', 'input' => '', 'expected' => "1\n"]);
        $case = [...new YqConformanceSuite($this->file)->cases()][0];

        $runner = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out): int {
            fwrite($out, "2\n");

            return 0;
        }));

        self::assertStringContainsString('stdout', (string)$case->evaluate($runner));
    }

    public function testNonZeroExitFailsEvenWithEmptyExpectedStdout(): void
    {
        $this->write(['name' => 'empty', 'command' => null, 'flags' => [], 'expression' => '.', 'input' => '', 'expected' => '']);
        $case = [...new YqConformanceSuite($this->file)->cases()][0];

        $runner = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out, mixed $err): int {
            fwrite($err, 'not implemented');

            return 70;
        }));

        $message = (string)$case->evaluate($runner);
        self::assertStringContainsString('exit code 70', $message);
        self::assertStringContainsString('not implemented', $message);
    }

    public function testMalformedFixtureThrows(): void
    {
        file_put_contents($this->file, '{"not":"a list"}');
        $this->expectException(UnexpectedValueException::class);

        [...new YqConformanceSuite($this->file)->cases()];
    }

    public function testMalformedCaseThrows(): void
    {
        $this->write(['name' => 'x']);
        $this->expectException(UnexpectedValueException::class);

        [...new YqConformanceSuite($this->file)->cases()];
    }

    /**
     * @param array<string, mixed> ...$rows
     */
    private function write(array ...$rows): void
    {
        file_put_contents($this->file, json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param Closure(list<string>, string, resource, resource): int $handler
     */
    private function controller(Closure $handler): FrontControllerInterface
    {
        return new readonly class($handler) implements FrontControllerInterface {
            /**
             * @param Closure(list<string>, string, resource, resource): int $handler
             */
            public function __construct(private Closure $handler)
            {
            }

            public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
            {
                return ($this->handler)(array_values($args), (string)stream_get_contents($stdin), $stdout, $stderr);
            }
        };
    }
}
