<?php

declare(strict_types=1);

namespace SnappedCut;

use RuntimeException;

/**
 * A refusal from the server, with its status.
 *
 * A distinct class because the two failures callers actually branch on --
 * "no credits" (402) and "slow down" (429) -- are not exceptional and should be
 * catchable without parsing a message. Status 0 means the server was never
 * reached, which is the one case worth sending again.
 */
final class SnappedCutError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly int $retryAfterMs = 0,
        /** On a 409 from a chunked upload: the byte the server's copy ends at. */
        public readonly int $expected = 0,
    ) {
        parent::__construct($message, $status);
    }

    public function outOfCredits(): bool
    {
        return $this->status === 402;
    }

    public function rateLimited(): bool
    {
        return $this->status === 429;
    }
}
