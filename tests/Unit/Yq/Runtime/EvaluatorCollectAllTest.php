<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YamlCodec;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YamlOnlyRegistry;
use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Expression\ExpressionParser;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\RuntimeServices;
use LTS\PhpXq\Yq\Runtime\SecurityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Evaluator::class)]
final class EvaluatorCollectAllTest extends TestCase
{
    public function testCollectGathersEveryMatchIntoOneSequence(): void
    {
        $parser   = new YamlParser();
        $emitter  = new YamlEmitter();
        $services = new RuntimeServices(
            new ExpressionParser(),
            $parser,
            new YamlOnlyRegistry(new YamlCodec($parser, $emitter)),
            new SecurityOptions(),
            false,
        );

        $candidates = [];
        foreach ($parser->parse("a: 1\n---\na: 2\n") as $index => $document) {
            $candidates[] = new Candidate($document, null, null, $index, 0, '');
        }

        $results = new Evaluator()->evaluate(new ExpressionParser()->parse('[.]'), new EvaluationContext($candidates, $services));

        self::assertCount(1, $results);
        self::assertSame("- a: 1\n- a: 2\n", $emitter->emit($results[0]->node, new EmitOptions()));
    }
}
