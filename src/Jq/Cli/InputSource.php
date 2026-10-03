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

    private ?Generator $generator = null;

    private bool $primed = false;

    private ?InputItem $lookahead = null;

    private bool $started = false;

    private ?string $filename = null;

    private int $line = 0;

    private bool $unreadable = false;

    private readonly ValueScanner $scanner;

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
        $this->scanner     = new ValueScanner();
        $this->diagnostics = new ParseDiagnostics($decoder);
        $this->streams     = new StreamParser($decoder, $this->diagnostics);
    }

    /**
     * Take the next item for the main loop, moving the current position to it.
     */
    public function fetch(): ?InputItem
    {
        $item = $this->lookahead ?? $this->pull();
        $this->lookahead = null;
        if (null === $item) {
            return null;
        }

        $this->started  = true;
        $this->filename = $item->filename;
        $this->line     = $item->line;

        return $item;
    }

    public function hasNext(): bool
    {
        $this->lookahead ??= $this->pull();

        return null !== $this->lookahead;
    }

    public function next(): mixed
    {
        $item = $this->fetch();
        if (null === $item) {
            throw JqException::fromMessage('No more inputs');
        }

        if (null !== $item->error) {
            throw JqException::fromMessage($item->error);
        }

        return $item->value;
    }

    public function lineNumber(): int
    {
        return $this->line;
    }

    /**
     * The file the current input came from; null for stdin or before any input.
     */
    public function filename(): ?string
    {
        return $this->filename;
    }

    /**
     * `file:line` for error messages, `<unknown>` before the first input was taken.
     */
    public function positionLabel(): string
    {
        return $this->started ? ($this->filename ?? '<stdin>') . ':' . $this->line : '<unknown>';
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

            yield InputItem::value(self::scrub($all), $name, substr_count($all, "\n"));

            return;
        }

        $all  = [];
        $name = null;
        $line = 0;
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
            $line  = $item->line;
        }

        yield InputItem::value($all, $name, $line);
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

            if (str_starts_with($text, self::BOM)) {
                $text = substr($text, 3);
            }

            $failed = false;
            $inner  = match (true) {
                $this->options->stream => $this->streamEvents($name, $text),
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

            yield InputItem::value(self::scrub($line), $name, $lines);
        }
    }

    /**
     * @return Generator<int, InputItem>
     */
    private function jsonValues(?string $name, string $text): Generator
    {
        $length  = \strlen($text);
        $offset  = 0;
        $counted = 0;
        $lines   = 0;
        $eol     = -1;
        $scanner = $this->scanner;

        while (true) {
            $status = $scanner->find($text, $offset);
            if (ValueScanner::NONE === $status) {
                return;
            }

            if (ValueScanner::FOUND === $status) {
                $start = $scanner->start;
                $end   = $scanner->end;

                try {
                    $value = $this->decoder->decodeOne(0 === $start && $end === $length ? $text : substr($text, $start, $end - $start));
                } catch (JsonSyntaxException) {
                    yield InputItem::error($this->diagnostics->message($text, $start), true, $name, $lines);

                    return;
                }

                $offset = $end;
                if ($eol < $offset) {
                    $found = strpos($text, "\n", $offset);
                    $eol   = false === $found ? \PHP_INT_MAX : $found;
                }

                $upto = \PHP_INT_MAX === $eol ? $length : $eol + 1;
                if ($upto > $counted) {
                    $lines  += substr_count($text, "\n", $counted, $upto - $counted);
                    $counted = $upto;
                }

                yield InputItem::value($value, $name, $lines);

                continue;
            }

            yield InputItem::error(
                $this->diagnostics->message($text, $scanner->start),
                true,
                $name,
                substr_count($text, "\n"),
            );

            return;
        }
    }

    /**
     * RFC 7464 input: the decoder reports a damaged record by throwing, so decoding is resumed at the
     * next record separator with everything before it blanked (newlines kept, so positions stay right).
     *
     * @return Generator<int, InputItem>
     */
    private function seqValues(?string $name, string $text): Generator
    {
        $length  = \strlen($text);
        $current = $text;
        $resumed = -1;

        while (true) {
            try {
                foreach ($this->decoder->decodeAll($current, true) as $value) {
                    yield InputItem::value($value, $name, 0);
                }

                return;
            } catch (JsonSyntaxException $jsonSyntaxException) {
                $message = $jsonSyntaxException->getMessage();
                $offset  = self::offsetOfPosition($text, $message);
                yield InputItem::error($message, false, $name, substr_count($text, "\n", 0, $offset ?? $length));

                if (null === $offset) {
                    return;
                }

                $from = max($offset - 1, $resumed + 1);
                if ($from >= $length) {
                    return;
                }

                $separator = strpos($text, "\x1e", $from);
                if (false === $separator) {
                    return;
                }

                $resumed = $separator;
                $current = (string)preg_replace('/[^\n]/', ' ', substr($text, 0, $separator)) . substr($text, $separator);
            }
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
     * The byte offset a "... at line L, column C" message refers to, or null when it carries no position.
     */
    private static function offsetOfPosition(string $text, string $message): ?int
    {
        if (1 !== preg_match('/at line (\d+), column (\d+)$/', $message, $matches)) {
            return null;
        }

        $offset = 0;
        for ($line = 1; $line < (int)$matches[1]; ++$line) {
            $newline = strpos($text, "\n", $offset);
            if (false === $newline) {
                return null;
            }

            $offset = $newline + 1;
        }

        return $offset + (int)$matches[2];
    }

    /**
     * Invalid UTF-8 becomes U+FFFD, as jq does for raw input.
     */
    private static function scrub(string $text): string
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
