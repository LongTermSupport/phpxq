<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Support;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Expression\ExpressionParser;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\RuntimeServices;
use LTS\PhpXq\Yq\Runtime\SecurityOptions;

/**
 * Runs a yq expression over YAML text through the real parser, evaluator and emitter.
 */
final class YqHarness
{
    private function __construct()
    {
    }

    public static function run(string $expression, string $input = '', bool $nullInput = false, bool $fixedMerge = false, ?SecurityOptions $security = null): string
    {
        $parser   = new YamlParser();
        $emitter  = new YamlEmitter();
        $codec    = new YamlCodec($parser, $emitter);
        $services = new RuntimeServices(
            new ExpressionParser(),
            $parser,
            new YamlOnlyRegistry($codec),
            $security ?? new SecurityOptions(enableSystemOperator: true),
            $fixedMerge,
        );
        $evaluator = new Evaluator();
        $expr      = new ExpressionParser()->parse($expression);

        $docs = [];
        if ($nullInput) {
            $docs[] = Node::document(Node::scalar('', '!!null'));
        } else {
            foreach ($parser->parse($input) as $doc) {
                $docs[] = $doc;
            }
        }

        $actual   = '';
        $previous = null;
        foreach ($docs as $index => $doc) {
            $context = new EvaluationContext([new Candidate($doc, null, null, $index, 0, '')], $services);
            foreach ($evaluator->evaluate($expr, $context) as $result) {
                if (null !== $previous && $previous !== $result->documentIndex) {
                    $actual .= "---\n";
                }

                $previous = $result->documentIndex;
                if (NodeKindEnum::Document === $result->node->kind) {
                    $result->node->explicitStart = false;
                }

                $actual .= $emitter->emit($result->node, new EmitOptions());
            }
        }

        return $actual;
    }
}
