<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;

/**
 * A format registry for CLI tests: every decoder yields one document holding the whole input as a
 * string scalar, every encoder writes `<format>:<index>:<value>`.
 */
final class FakeFormats implements FormatRegistryInterface
{
    /** @var list<FormatOptions> */
    public array $seenOptions = [];

    public function decoder(Format $format): DecoderInterface
    {
        return new readonly class($format, $this) implements DecoderInterface {
            public function __construct(private Format $format, private FakeFormats $owner)
            {
            }

            public function format(): Format
            {
                return $this->format;
            }

            public function decode(string $input, FormatOptions $options): iterable
            {
                $this->owner->seenOptions[] = $options;

                yield Node::document(Node::scalar(rtrim($input, "\n"), '!!str'));
            }
        };
    }

    public function encoder(Format $format): EncoderInterface
    {
        return new readonly class($format, $this) implements EncoderInterface {
            public function __construct(private Format $format, private FakeFormats $owner)
            {
            }

            public function format(): Format
            {
                return $this->format;
            }

            public function encode(Node $node, FormatOptions $options, int $resultIndex): string
            {
                $this->owner->seenOptions[] = $options;

                return $this->format->value . ':' . $resultIndex . ':' . $node->value . "\n";
            }
        };
    }
}
