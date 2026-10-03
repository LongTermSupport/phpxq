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

        try {
            $this->program->run($this->context, $value, $this->emit);
        } catch (JqException $jqException) {
            $this->reportError($jqException->value);
            $this->status = self::ERROR;
        } catch (HaltException $haltException) {
            if (null !== $haltException->stderrText) {
                $this->console->err($haltException->stderrText);
            }

            $this->halted = true;
            $this->status = $haltException->exitCode;
        } catch (OutputAbortException $outputAbortException) {
            if ('' !== $outputAbortException->getMessage()) {
                $this->reportError($outputAbortException->getMessage());
            }

            $this->status = self::ERROR;
        }

        return $this->status;
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
        $suffix   = $options->rawOutput0 ? "\0" : ($options->joinOutput ? '' : "\n");

        return function (mixed $result) use ($encoder, $encode, $console, $flat, $raw, $ascii, $rawNul, $seq, $flush, $suffix): void {
            if (\is_string($result) && $raw && !$ascii) {
                if ($rawNul && str_contains($result, "\0")) {
                    throw new OutputAbortException(self::NUL_MESSAGE);
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
                throw new OutputAbortException('');
            }
        };
    }
}
