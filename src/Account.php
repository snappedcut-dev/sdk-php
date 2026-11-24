<?php

declare(strict_types=1);

namespace SnappedCut;

final class Account
{
    /** @param array<string, int> $prices */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly int $credits,
        public readonly array $prices,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            Decode::str($raw, 'id'),
            Decode::str($raw, 'email'),
            Decode::int($raw, 'credits'),
            Decode::ints($raw, 'prices'),
        );
    }
}
