<?php

declare(strict_types=1);

namespace SnappedCut;

final class Job
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $state,
        public readonly float $seconds,
        public readonly ?string $error = null,
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            Decode::str($raw, 'id'),
            Decode::str($raw, 'kind', 'cloud-render'),
            Decode::str($raw, 'state', 'queued'),
            Decode::float($raw, 'seconds'),
            Decode::nullableStr($raw, 'error'),
            Decode::int($raw, 'createdAt'),
            Decode::int($raw, 'updatedAt'),
        );
    }
}
