<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidRepeatedStringLiteralRule;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use QaConfig\PHPStan\Rules\ProductionOnlyRule;

/**
 * @internal
 *
 * @extends RuleTestCase<ProductionOnlyRule<InClassNode>>
 */
#[CoversNothing]
final class ProductionOnlyRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/ProductionOnly';

    public function testItKeepsTheInnerRuleForAProductionPath(): void
    {
        $this->analyse([self::FIXTURES . '/src/RepeatsLiteral.php'], [
            [
                "String literal 'repeated-value' appears 3 times in this class (lines 11, 16, 21); declare it once as a class constant.",
                11,
            ],
        ]);
    }

    public function testItStaysSilentForAPathUnderTheExcludedDirectory(): void
    {
        $this->analyse([self::FIXTURES . '/tests/RepeatsLiteral.php'], []);
    }

    public function testItAnswersTheInnerRulesNodeType(): void
    {
        self::assertSame(InClassNode::class, $this->getRule()->getNodeType());
        self::assertNotSame(Class_::class, $this->getRule()->getNodeType());
    }

    /**
     * @return ProductionOnlyRule<InClassNode>
     */
    protected function getRule(): Rule
    {
        return new ProductionOnlyRule(new ForbidRepeatedStringLiteralRule(), (string)realpath(self::FIXTURES . '/tests'));
    }
}
