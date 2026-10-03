<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Runtime\CompiledProgram;
use LTS\PhpXq\Jq\Runtime\HaltException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoderInterface;

/**
 * Runs the compiled program on one input at a time and writes what it outputs, the way jq's main loop
 * does: each output is formatted per the options, an uncaught error is reported on stderr and the run
 * goes on with the next input, `halt` stops everything.
 *
 * {@see self::process()} returns jq's status for one input: NO_OUTPUT when the program produced nothing,
 * NULL_KIND when the last output was false or null, 0 otherwise, or a positive exit status after an
 * error or `halt`.
 *
 * @api
 */
final class ProgramRunner
{
    public const int NO_OUTPUT = -4;

    public const int NULL_KIND = -1;

    public const int OK = 0;

    public const int ERROR = 5;

    private const string NUL_MESSAGE = 'Cannot dump a string containing NUL with --raw-output0 option';

    public bool $halted = false;

    private int $status = self::NO_OUTPUT;

    private ?string $abortMessage = null;

    /** @var Closure(mixed): void */
    private readonly Closure $emit;

    public function __construct(
        private readonly CompiledProgram $program,
        private readonly RuntimeContext $context,
        private readonly InputSource $input,
        private readonly Console $console,
        private readonly JsonEncoderInterface $encoder,
        private readonly CliOptions $options,
        private readonly EncodeOptions $encodeOptions,
    ) {
        $this->emit = $this->buildEmitter();
    }

    public function process(mixed $value): int
    {
        $this->status = self::NO_OUTPUT;

        $this->abortMessage = null;

        try {
            $this->program->run($this->context, $value, $this->emit);
        } catch (JqException $jqException) {
            $this->reportError($jqException->value);
            $this->status = self::ERROR;
        } catch (HaltException $haltException) {
            $this->finish($haltException);
        }

        return $this->status;
    }

    /**
     * A {@see HaltException} is either the program's own `halt` / `halt_error`, or the output step ending
     * this input (it is the one exception no jq construct can catch).
     */
    private function finish(HaltException $haltException): void
    {
        if (null !== $this->abortMessage) {
            if ('' !== $this->abortMessage) {
                $this->reportError($this->abortMessage);
            }

            $this->status = self::ERROR;

            return;
        }

        if (null !== $haltException->stderrText) {
            $this->console->err($haltException->stderrText);
        }

        $this->halted = true;
        $this->status = $haltException->exitCode;
    }

    /**
     * `jq: error (at <file>:<line>): message`, or `(not a string): <json>` for an error value that is
     * not a string.
     */
    private function reportError(mixed $value): void
    {
        $position = $this->input->positionLabel();
        if (\is_string($value)) {
            $this->console->err('jq: error (at ' . $position . '): ' . $value . "\n");

            return;
        }

        $this->console->err('jq: error (at ' . $position . ') (not a string): ' . $this->encoder->encode($value, EncodeOptions::compact()) . "\n");
    }

    /**
     * @return Closure(mixed): void
     */
    private function buildEmitter(): Closure
    {
        $options  = $this->options;
        $encoder  = $this->encoder;
        $encode   = $this->encodeOptions;
        $console  = $this->console;
        $flat     = $options->isFlatPretty();
        $raw      = $options->rawOutput;
        $ascii    = $options->ascii;
        $rawNul   = $options->rawOutput0;
        $seq      = $options->seq;
        $flush    = $options->unbuffered;
        $suffix   = match (true) {
            $options->rawOutput0  => "\0",
            $options->joinOutput  => '',
            default               => "\n",
        };

        return function (mixed $result) use ($encoder, $encode, $console, $flat, $raw, $ascii, $rawNul, $seq, $flush, $suffix): void {
            if (\is_string($result) && $raw && !$ascii) {
                if ($rawNul && str_contains($result, "\0")) {
                    $this->abortMessage = self::NUL_MESSAGE;

                    throw new HaltException(self::ERROR);
                }

                $text = $result;
            } else {
                $text = $encoder->encode($result, $encode);
                if ($flat) {
                    $text = (string)preg_replace('/^ +/m', '', $text);
                }
            }

            $console->out($seq ? "\x1e" . $text . $suffix : $text . $suffix);
            $this->status = null === $result || false === $result ? self::NULL_KIND : self::OK;

            if ($flush) {
                $console->flush();
            }

            if ($console->stdoutFailed()) {
                $this->abortMessage = '';

                throw new HaltException(self::ERROR);
            }
        };
    }
}
