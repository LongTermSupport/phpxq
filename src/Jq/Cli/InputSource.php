<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use Closure;
use Generator;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Runtime\InputPositionInterface;
use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonSyntaxException;
use RuntimeException;

/**
 * Everything the program can read: the input files in order (or stdin), read lazily, in the shape the
 * options ask for (JSON values, raw lines, one slurped value, `--stream` events, `--seq` records).
 *
 * The main loop and the `input` / `inputs` builtins pull from the same source, so they share one
 * position. An unreadable file is reported and skipped (the run then exits with status 2); a parse
 * error ends the input unless `--seq` is on, which skips to the next record separator.
 *
 * @api
 */
final class InputSource implements InputProviderInterface, InputPositionInterface
{
    private const string BOM = "\xEF\xBB\xBF";

    /** @var ?Generator<int, InputItem> */
    private ?Generator $generator = null;

    private bool $primed = false;

    private ?InputItem $lookahead = null;

    private ?InputItem $current = null;

    private bool $unreadable = false;

    private readonly ParseDiagnostics $diagnostics;

    private readonly StreamParser $streams;

    /**
     * @param list<string>          $files
     * @param resource              $stdin
     * @param Closure(string): void $warn  receives each complete stderr message about an unreadable file
     */
    public function __construct(
        private readonly array $files,
        private $stdin,
        private readonly JsonDecoderInterface $decoder,
        private readonly CliOptions $options,
        private readonly Closure $warn,
    ) {
        $this->diagnostics = new ParseDiagnostics($decoder);
        $this->streams     = new StreamParser($decoder, $this->diagnostics);
    }

    /**
     * Take the next item for the main loop, moving the current position to it.
     */
    public function fetch(): ?InputItem
    {
        $item            = $this->lookahead ?? $this->pull();
        $this->lookahead = null;
        if (!$item instanceof InputItem) {
            return null;
        }

        $this->current = $item;

        return $item;
    }

    public function hasNext(): bool
    {
        $this->lookahead ??= $this->pull();

        return $this->lookahead instanceof InputItem;
    }

    public function next(): mixed
    {
        $item = $this->fetch();
        if (!$item instanceof InputItem) {
            throw JqException::fromMessage('No more inputs');
        }

        if (null !== $item->error) {
            throw JqException::fromMessage($item->error);
        }

        return $item->value;
    }

    public function lineNumber(): int
    {
        return $this->current?->lineNumber() ?? 0;
    }

    /**
     * The file the current input came from; null for stdin or before any input.
     */
    public function filename(): ?string
    {
        return $this->current?->filename;
    }

    /**
     * `file:line` for error messages, `<unknown>` before the first input was taken.
     */
    public function positionLabel(): string
    {
        return $this->current instanceof InputItem ? ($this->current->filename ?? '<stdin>') . ':' . $this->current->lineNumber() : '<unknown>';
    }

    public function hadUnreadableFile(): bool
    {
        return $this->unreadable;
    }

    private function pull(): ?InputItem
    {
        $generator = $this->generator ??= $this->produce();
        if ($this->primed) {
            $generator->next();
        } else {
            $this->primed = true;
        }

        if (!$generator->valid()) {
            return null;
        }

        return $generator->current();
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function produce(): Generator
    {
        if (!$this->options->slurp) {
            yield from $this->items();

            return;
        }

        if ($this->options->rawInput) {
            $all  = '';
            $name = null;
            foreach ($this->sources() as [$source, $text]) {
                $all .= $text;
                $name = $source;
            }

            yield InputItem::value($this->scrub($all), $name, substr_count($all, "\n"));

            return;
        }

        $all  = [];
        $name = null;
        $last = null;
        foreach ($this->items() as $item) {
            if ($item->isError()) {
                yield $item;
                if ($item->fatal) {
                    return;
                }

                continue;
            }

            $all[] = $item->value;
            $name  = $item->filename;
            $last  = $item;
        }

        yield InputItem::value($all, $name, $last?->lineNumber() ?? 0);
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function items(): Generator
    {
        foreach ($this->sources() as [$name, $text]) {
            if ($this->options->rawInput) {
                yield from $this->rawLines($name, $text);

                continue;
            }

            $failed = false;
            $inner  = match (true) {
                $this->options->stream => $this->streamEvents($name, str_starts_with($text, self::BOM) ? substr($text, 3) : $text),
                $this->options->seq    => $this->seqValues($name, $text),
                default                => $this->jsonValues($name, $text),
            };
            foreach ($inner as $item) {
                yield $item;
                if ($item->isError() && $item->fatal) {
                    $failed = true;
                }
            }

            if ($failed) {
                return;
            }
        }
    }

    /**
     * @return Generator<int, array{?string, string}>
     */
    private function sources(): Generator
    {
        if ([] === $this->files) {
            yield [null, $this->readStdin()];

            return;
        }

        foreach ($this->files as $file) {
            if ('-' === $file) {
                yield [null, $this->readStdin()];

                continue;
            }

            try {
                $text = FileReader::read($file);
            } catch (RuntimeException $runtimeException) {
                $this->unreadable = true;
                ($this->warn)('jq: error: ' . $runtimeException->getMessage() . "\n");

                continue;
            }

            yield [$file, $text];
        }
    }

    private function readStdin(): string
    {
        $contents = stream_get_contents($this->stdin);

        return false === $contents ? '' : $contents;
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function rawLines(?string $name, string $text): Generator
    {
        $length = \strlen($text);
        $offset = 0;
        $lines  = 0;
        while ($offset < $length) {
            $newline = strpos($text, "\n", $offset);
            if (false === $newline) {
                $line   = substr($text, $offset);
                $offset = $length;
            } else {
                $line   = substr($text, $offset, $newline - $offset);
                $offset = $newline + 1;
                ++$lines;
            }

            yield InputItem::value($this->scrub($line), $name, $lines);
        }
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function jsonValues(?string $name, string $text): Generator
    {
        $tracker = new LineTracker(str_starts_with($text, self::BOM) ? substr($text, 3) : $text);
        $ordinal = 0;

        try {
            foreach ($this->decoder->decodeAll($text) as $value) {
                yield InputItem::tracked($value, $name, $tracker, ++$ordinal);
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            $message = $jsonSyntaxException->getMessage();

            yield InputItem::error($message, true, $name, $this->linesBeforeError($text, $message));
        }
    }

    /**
     * RFC 7464 input. The decoder yields a {@see JsonSyntaxException} in place of a damaged record and
     * carries on with the next one, which becomes a warning item here.
     *
     * @return Generator<int, InputItem>
     */
    private function seqValues(?string $name, string $text): Generator
    {
        try {
            foreach ($this->decoder->decodeAll($text, true) as $value) {
                if ($value instanceof JsonSyntaxException) {
                    yield InputItem::error($value->getMessage(), false, $name, $this->linesBeforeError($text, $value->getMessage()));

                    continue;
                }

                yield InputItem::value($value, $name, 0);
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            yield InputItem::error($jsonSyntaxException->getMessage(), true, $name, $this->linesBeforeError($text, $jsonSyntaxException->getMessage()));
        }
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function streamEvents(?string $name, string $text): Generator
    {
        $counted = 0;
        $lines   = 0;
        foreach ($this->streams->events($text, $this->options->seq) as $offset => $event) {
            $upto = strpos($text, "\n", $offset);
            $upto = false === $upto ? \strlen($text) : $upto + 1;
            if ($upto > $counted) {
                $lines  += substr_count($text, "\n", $counted, $upto - $counted);
                $counted = $upto;
            }

            if (!$event instanceof StreamError) {
                yield InputItem::value($event, $name, $lines);

                continue;
            }

            if ($this->options->streamErrors) {
                yield InputItem::value([$event->message, $event->path], $name, $lines);

                if ($event->fatal) {
                    return;
                }

                continue;
            }

            yield InputItem::error($event->message, $event->fatal, $name, $lines);
        }
    }

    /**
     * The number of newlines read when a parse error with "... at line L, column C" was found: L - 1, or
     * every newline of the text for a message without a position.
     */
    private function linesBeforeError(string $text, string $message): int
    {
        if (1 !== preg_match('/at line (\d+), column \d+/', $message, $matches)) {
            return substr_count($text, "\n");
        }

        return max(0, (int)$matches[1] - 1);
    }

    /**
     * Invalid UTF-8 becomes U+FFFD, as jq does for raw input.
     */
    private function scrub(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
