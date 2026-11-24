<?php

declare(strict_types=1);

namespace SnappedCut;

/** What the server decided the job was, and therefore what it cost. */
final class Priced
{
    public function __construct(
        public readonly float $seconds,
        public readonly string $project,
        public readonly int $layers,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            Decode::float($raw, 'seconds'),
            Decode::str($raw, 'project'),
            Decode::int($raw, 'layers'),
        );
    }
}
