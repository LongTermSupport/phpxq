<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * A plain `MAJOR.MINOR.PATCH` version. Pre-release and build suffixes are not part of the automated flow:
 * a pre-release is cut by hand, so such a VERSION is refused rather than guessed at.
 */
final readonly class SemVer
{
    private const string PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';

    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    public static function parse(string $version): self
    {
        if (1 !== preg_match(self::PATTERN, $version, $parts)) {
            throw new ReleaseException(\sprintf('"%s" is not a plain MAJOR.MINOR.PATCH version', $version));
        }

        return new self((int)$parts[1], (int)$parts[2], (int)$parts[3]);
    }

    public function bump(BumpEnum $bump): self
    {
        return match ($bump) {
            BumpEnum::Major => new self($this->major + 1, 0, 0),
            BumpEnum::Minor => new self($this->major, $this->minor + 1, 0),
            BumpEnum::Patch => new self($this->major, $this->minor, $this->patch + 1),
        };
    }

    public function toString(): string
    {
        return \sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
    }
}
