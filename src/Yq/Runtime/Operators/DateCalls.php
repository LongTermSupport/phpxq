<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Expression\Ast\Call;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\CallOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\GoTime;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Numbers;

/**
 * Date and time operators: `now`, `from_unix`, `to_unix`, `tz`, `format_datetime` and `with_dtf`, which
 * sets the layout the date operators in its second argument read and write.
 */
final class DateCalls implements CallOperatorInterface
{
    public function names(): array
    {
        return ['now', 'from_unix', 'to_unix', 'tz', 'format_datetime', 'with_dtf'];
    }

    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        if ('with_dtf' === $call->name) {
            $call = Args::split($call, 2);
            Args::require($call, 2);
            $layout = Args::string($call, 0, $context, $evaluator, $context->matches[0] ?? null) ?? GoTime::RFC3339;

            return $evaluator->evaluate($call->arguments[1], $context->withVariable('__dtf', [new Candidate(NodeOps::str($layout))]));
        }

        $out    = [];
        $layout = Cands::dateLayout($context);
        foreach ($context->matches as $match) {
            $out[] = $this->one($call, $match, $layout, $context, $evaluator);
        }

        return $out;
    }

    private function one(Call $call, Candidate $match, ?string $layout, EvaluationContext $context, EvaluatorInterface $evaluator): Candidate
    {
        $node = NodeOps::deref(Cands::node($match));
        switch ($call->name) {
            case 'now':
                return Cands::derive($this->dateNode(new DateTimeImmutable('now'), $layout), $match);

            case 'from_unix':
                $seconds = Numbers::of($node);
                if (null === $seconds) {
                    throw new EvaluationException(\sprintf('cannot convert %s to a unix time', $node->value));
                }

                $time = new DateTimeImmutable('@' . (int)$seconds)->setTimezone(new DateTimeZone(date_default_timezone_get()));

                return Cands::derive($this->dateNode($time, $layout), $match);

            case 'to_unix':
                return Cands::derive(NodeOps::int($this->parse($node, $layout)->getTimestamp()), $match);

            case 'tz':
                Args::require($call, 1);
                $zone = Args::string($call, 0, $context, $evaluator, $match) ?? 'UTC';
                try {
                    $target = new DateTimeZone($zone);
                } catch (Exception) {
                    throw new EvaluationException(\sprintf('unknown time zone %s', $zone));
                }

                return Cands::derive($this->dateNode($this->parse($node, $layout)->setTimezone($target), $layout), $match);

            default:
                Args::require($call, 1);
                $format = Args::string($call, 0, $context, $evaluator, $match) ?? GoTime::RFC3339;

                return Cands::derive($this->textNode(GoTime::format($this->parse($node, $layout), $format)), $match);
        }
    }

    private function textNode(string $text): Node
    {
        return new Node(NodeKind::Scalar, GoTime::looksLikeTimestamp($text) ? CoreSchema::TAG_TIMESTAMP : CoreSchema::TAG_STR, NodeStyle::Default, $text);
    }

    private function parse(Node $node, ?string $layout): DateTimeImmutable
    {
        if (NodeKind::Scalar !== $node->kind) {
            throw new EvaluationException('Cannot parse a collection as a datetime');
        }

        $time = GoTime::tryParse($node->value, $layout);
        if (!$time instanceof DateTimeImmutable) {
            throw new EvaluationException(\sprintf('Could not parse datetime/timestamp for %s using format %s', $node->value, $layout ?? GoTime::RFC3339));
        }

        return $time;
    }

    private function dateNode(DateTimeImmutable $time, ?string $layout): Node
    {
        return $this->textNode(GoTime::format($time, $layout ?? GoTime::RFC3339));
    }
}
