<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

/**
 * A documented example the extractor could not turn into an unambiguous case, and why.
 */
final readonly class YqSkip
{
    public function __construct(
        public string $source,
        public string $heading,
        public string $reason,
        public string $snippet,
    ) {
    }

    /**
     * @return array{source: string, heading: string, reason: string, snippet: string}
     */
    public function toArray(): array
    {
        return [
            'source'  => $this->source,
            'heading' => $this->heading,
            'reason'  => $this->reason,
            'snippet' => $this->snippet,
        ];
    }
}
