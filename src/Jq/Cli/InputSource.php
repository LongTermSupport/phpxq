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
 * CHUNK is the bytes asked of standard input per read (a pipe hands over what has arrived, up to this much).
 * The stream parser is only needed for `--stream` and is built on demand so that other runs do not load its
 * classes.
 *
 * @api
 */
final class InputSource implements InputProviderInterface, InputPositionInterface
{
    private const string ENCODING = 'UTF-8';

    private const string BOM ="\xEF\xBB\xBF";

    private const int CHUNK = 65536;

    /** @var ?Generator<int, InputItem> */
    private ?Generator $generator = null;

    private bool $primed = false;

    private ?InputItem $lookahead = null;

    private ?InputItem $current = null;

    private bool $unreadable = false;

    private ?StreamParser $streams = null;

    /**
     * @param list<string>          $files
     * @param resource              $stdin
     * @param Closure(string): void $warn  receives each complete stderr message about an unreadable file
     */
    public function __construct(
        private readonly array $files,
        private readonly mixed $stdin,
        private readonly JsonDecoderInterface $decoder,
        private readonly CliOptions $options,
        private readonly Closure $warn,
    ) {
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
        $incremental = !$this->options->slurp && !$this->options->stream && !$this->options->seq;
        foreach ($this->sources($incremental) as [$name, $text, $firstLine]) {
            if ($this->options->rawInput) {
                yield from $this->rawLines($name, $text, $firstLine);

                continue;
            }

            $failed = false;
            $inner  = match (true) {
                $this->options->stream => $this->streamEvents($name, str_starts_with($text, self::BOM) ? substr($text, 3) : $text),
                $this->options->seq    => $this->seqValues($name, $text),
                default                => $this->jsonValues($name, $text, $firstLine),
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
     * The texts to parse, each with the number of newlines that came before it in its source. A file is
     * one text. Standard input is one text too, unless $incremental asks for it in pieces that each end
     * between two values, so a pipe is processed while it is still being written.
     *
     * @return Generator<int, array{?string, string, int}>
     */
    private function sources(bool $incremental = false): Generator
    {
        if ([] === $this->files) {
            yield from $this->stdinTexts($incremental);

            return;
        }

        foreach ($this->files as $file) {
            if ('-' === $file) {
                yield from $this->stdinTexts($incremental);

                continue;
            }

            try {
                $text = FileReader::read($file);
            } catch (RuntimeException $runtimeException) {
                $this->unreadable = true;
                ($this->warn)('jq: error: ' . $runtimeException->getMessage() . "\n");

                continue;
            }

            yield [$file, $text, 0];
        }
    }

    /**
     * @return Generator<int, array{null, string, int}>
     */
    private function stdinTexts(bool $incremental): Generator
    {
        if (!$incremental) {
            yield [null, $this->readStdin(), 0];

            return;
        }

        $segmenter = new InputSegmenter(!$this->options->rawInput);
        $lines     = 0;
        while (null !== ($chunk = $this->readChunk())) {
            $segment = $segmenter->push($chunk);
            if (null !== $segment) {
                yield [null, $segment, $lines];

                $lines += substr_count($segment, "\n");
            }
        }

        $rest = $segmenter->finish();
        if ('' !== $rest) {
            yield [null, $rest, $lines];
        }
    }

    private function readStdin(): string
    {
        $contents = '';
        while (null !== ($chunk = $this->readChunk())) {
            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * The next piece of standard input, or null at the end of it. A read that fails (standard input is a
     * directory, say) is reported like an unreadable file and ends the input.
     */
    private function readChunk(): ?string
    {
        if (feof($this->stdin)) {
            return null;
        }

        $reason = null;
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            // "fread(): Read of 8192 bytes failed with errno=21 Is a directory"
            $reason = 1 === preg_match('/errno=\d+ (.+)$/', $message, $match) ? $match[1] : $message;

            return true;
        });

        try {
            $chunk = fread($this->stdin, self::CHUNK);
        } finally {
            restore_error_handler();
        }

        if (null !== $reason || false === $chunk) {
            $this->unreadable = true;
            ($this->warn)('jq: error: ' . ($reason ?? 'Input/output error') . "\n");

            return null;
        }

        return '' === $chunk && feof($this->stdin) ? null : $chunk;
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function rawLines(?string $name, string $text, int $firstLine = 0): Generator
    {
        $length = \strlen($text);
        $offset = 0;
        $lines  = $firstLine;
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
    private function jsonValues(?string $name, string $text, int $firstLine = 0): Generator
    {
        $tracker = new LineTracker(str_starts_with($text, self::BOM) ? substr($text, 3) : $text, $firstLine);
        $ordinal = 0;

        try {
            foreach ($this->decoder->decodeAll($text) as $value) {
                yield InputItem::tracked($value, $name, $tracker, ++$ordinal);
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            $message = $this->shiftLines($jsonSyntaxException->getMessage(), $firstLine);

            yield InputItem::error($message, true, $name, $firstLine + $this->linesBeforeError($text, $jsonSyntaxException->getMessage()));
        }
    }

    /**
     * Make the "at line L, column C" of a message about a piece of the input relative to the whole input.
     */
    private function shiftLines(string $message, int $firstLine): string
    {
        if (0 === $firstLine) {
            return $message;
        }

        return (string)preg_replace_callback(
            '/at line (\d+), column (\d+)/',
            static fn (array $match): string => 'at line ' . ((int)$match[1] + $firstLine) . ', column ' . $match[2],
            $message,
        );
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
        $length  = \strlen($text);
        // offset of the next newline at or after the last event; cached so a long single line is scanned once
        $eol     = -1;
        $streams = $this->streams ??= new StreamParser($this->decoder, new ParseDiagnostics($this->decoder));
        foreach ($streams->events($text, $this->options->seq) as $offset => $event) {
            if ($eol < $offset) {
                $found = strpos($text, "\n", $offset);
                $eol   = false === $found ? \PHP_INT_MAX : $found;
            }

            $upto = \PHP_INT_MAX === $eol ? $length : $eol + 1;
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
        if (mb_check_encoding($text, self::ENCODING)) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_convert_encoding($text, self::ENCODING, self::ENCODING);
        } finally {
            mb_substitute_character($previous);
        }
    }
}
