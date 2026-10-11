<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The in-place (`-i`) output: results are collected in memory and, only if the whole run succeeded and the
 * text differs from the file, written to a temporary file next to the target which then replaces it, with
 * the target's permissions kept.
 *
 * @internal
 */
final readonly class InPlaceTarget
{
    /** @var resource */
    private mixed $buffer;

    private string $path;

    /**
     * @throws CliException
     */
    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new CliException(\sprintf('open %s: no such file or directory', $path));
        }

        $resolved   = realpath($path);
        $this->path = false === $resolved ? $path : $resolved;

        $buffer = fopen('php://temp', 'w+b');
        if (false === $buffer) {
            throw new CliException('could not allocate the in-place buffer');
        }

        $this->buffer = $buffer;
    }

    /**
     * @return resource
     */
    public function stream(): mixed
    {
        return $this->buffer;
    }

    /**
     * @throws CliException
     */
    public function commit(): void
    {
        rewind($this->buffer);
        $new = (string)stream_get_contents($this->buffer);
        if (file_get_contents($this->path) === $new) {
            return;
        }

        $permissions = fileperms($this->path);
        $temporary   = tempnam(\dirname($this->path), '.yq-');
        if (false === $temporary) {
            throw new CliException(\sprintf('could not create a temporary file next to %s', $this->path));
        }

        if (false === file_put_contents($temporary, $new)) {
            unlink($temporary);

            throw new CliException(\sprintf('could not write %s', $temporary));
        }

        if (false !== $permissions) {
            chmod($temporary, $permissions & 0o7777);
        }

        if (!rename($temporary, $this->path)) {
            unlink($temporary);

            throw new CliException(\sprintf('could not replace %s', $this->path));
        }
    }
}
