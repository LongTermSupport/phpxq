<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The words that can name a command on the `yq` command line, valued with the word typed. The short forms are
 * aliases; {@see self::canonical()} resolves one to the command it names.
 */
enum CommandEnum: string
{
    public function canonical(): self
    {
        return match ($this) {
            self::EvalShort    => self::Eval,
            self::EvalAllShort => self::EvalAll,
            default            => $this,
        };
    }

    /**
     * Whether this is one of the two hidden commands the generated completion scripts call.
     */
    public function isCompletionRequest(): bool
    {
        return self::Complete === $this || self::CompleteNoDescriptions === $this;
    }

    case Eval = 'eval';

    case EvalShort = 'e';

    case EvalAll = 'eval-all';

    case EvalAllShort = 'ea';

    case Completion = 'completion';

    case Help = 'help';

    case Complete = '__complete';

    case CompleteNoDescriptions = '__completeNoDesc';
}
