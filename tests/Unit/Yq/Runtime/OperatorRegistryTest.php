<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\OperatorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OperatorRegistry::class)]
final class OperatorRegistryTest extends TestCase
{
    public function testCallTableMatchesTheNamesEachClassReports(): void
    {
        $reported = [];
        foreach (array_unique(array_values(OperatorRegistry::CALLS)) as $class) {
            foreach (new $class()->names() as $name) {
                $reported[$name] = $class;
            }
        }

        self::assertEquals($reported, OperatorRegistry::CALLS);
    }

    public function testBinaryTableMatchesTheOperatorsEachClassReports(): void
    {
        $reported = [];
        foreach (array_unique(array_values(OperatorRegistry::BINARIES)) as $class) {
            foreach (new $class()->operators() as $symbol) {
                $reported[$symbol->value] = $class;
            }
        }

        self::assertEquals($reported, OperatorRegistry::BINARIES);
    }

    public function testEveryBinaryOperatorResolves(): void
    {
        $registry = new OperatorRegistry();
        foreach (BinaryOperatorEnum::cases() as $operator) {
            self::assertNotNull($registry->binary($operator), $operator->value);
        }
    }

    public function testUnknownNamesResolveToNull(): void
    {
        self::assertNull(new OperatorRegistry()->call('no_such_operator'));
    }

    public function testFirstUseRegistersTheWholeFamily(): void
    {
        $registry = new OperatorRegistry();
        $select   = $registry->call('select');

        self::assertNotNull($select);
        self::assertSame($select, $registry->call('has'));
    }

    public function testAReplacementRegisteredBeforeFirstUseKeepsItsName(): void
    {
        $replacement = new class implements CallOperatorInterface {
            public function names(): array
            {
                return ['select'];
            }

            public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
            {
                return [];
            }
        };

        $registry = new OperatorRegistry();
        $registry->registerCall($replacement);

        self::assertSame($replacement, $registry->call('select'));
        self::assertNotSame($replacement, $registry->call('has'));
        self::assertSame($replacement, $registry->call('select'));
    }
}
