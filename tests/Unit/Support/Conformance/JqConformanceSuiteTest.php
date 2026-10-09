<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Conformance;

use Closure;
use LTS\PhpXq\Cli\FrontControllerInterface;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use LTS\PhpXq\Tests\Support\Conformance\JqConformanceSuite;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqConformanceSuiteTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpxq-jq-suite-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/fixtures', 0o777, true);
        file_put_contents(
            $this->dir . '/fixtures/mini.test',
            "# comment\n.a\n{\"a\":1}\n1\n\n%%FAIL\n.[\njq: error\n\n.\n[1,2]\n[1,2]\n",
        );
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/fixtures/mini.test');
        rmdir($this->dir . '/fixtures');
        rmdir($this->dir);
    }

    public function testNameAndIds(): void
    {
        $suite = new JqConformanceSuite($this->dir, ['mini.test']);

        self::assertSame('jq', $suite->name());
        self::assertSame(['mini.test:2', 'mini.test:7', 'mini.test:10'], $this->ids($suite));
    }

    public function testPassingCases(): void
    {
        $runner = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out, mixed $err): int {
            if (\in_array('-n', $args, true)) {
                fwrite($err, 'compile error');

                return 3;
            }

            fwrite($out, '.a' === $args[\count($args) - 1] ? "1\n" : "[1, 2]\n");

            return 0;
        }));

        foreach ($this->cases() as $case) {
            self::assertNull($case->evaluate($runner), $case->id);
        }
    }

    public function testFailureMessages(): void
    {
        $runner = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out, mixed $err): int {
            fwrite($out, "2\n");
            fwrite($err, 'boom');

            return 1;
        }));

        $messages = array_map(static fn (ConformanceCase $case): ?string => $case->evaluate($runner), $this->cases());

        self::assertStringContainsString('exit code 1', (string)$messages[0]);
        self::assertStringContainsString('boom', (string)$messages[0]);
        self::assertStringContainsString('exit code 3', (string)$messages[1]);
        self::assertStringContainsString('exit code 0', (string)$messages[2]);
    }

    public function testWrongOutputValuesAndNonJsonOutput(): void
    {
        $wrong = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out): int {
            fwrite($out, \in_array('-n', $args, true) ? '' : "2\n");

            return \in_array('-n', $args, true) ? 3 : 0;
        }));
        $cases = $this->cases();

        self::assertStringContainsString('output', (string)$cases[0]->evaluate($wrong));
        self::assertNull($cases[1]->evaluate($wrong));

        $garbage = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out): int {
            fwrite($out, "not json\n");

            return 0;
        }));

        self::assertStringContainsString('Not a JSON text', (string)$cases[0]->evaluate($garbage));
    }

    public function testFailCaseMustHaveEmptyStdout(): void
    {
        $runner = new CliRunner($this->controller(static function (array $args, string $stdin, mixed $out): int {
            fwrite($out, 'x');

            return 3;
        }));

        self::assertNotNull($this->cases()[1]->evaluate($runner));
    }

    public function testPassesModulesDirectoryAndInput(): void
    {
        $captured = [];
        $runner   = new CliRunner($this->controller(static function (array $args, string $stdin) use (&$captured): int {
            $captured = [$args, $stdin];

            return 0;
        }));

        $this->cases()[0]->evaluate($runner);

        self::assertSame(['jq', '-L', $this->dir . '/modules', '-c', '--', '.a'], $captured[0]);
        self::assertSame("{\"a\":1}\n", $captured[1]);
    }

    /**
     * @return list<ConformanceCase>
     */
    private function cases(): array
    {
        return array_values([...new JqConformanceSuite($this->dir, ['mini.test'])->cases()]);
    }

    /**
     * @return list<string>
     */
    private function ids(JqConformanceSuite $suite): array
    {
        return array_values(array_map(static fn (ConformanceCase $case): string => $case->id, [...$suite->cases()]));
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
