<?php

declare(strict_types=1);

namespace SnappedCut;

/** A job plus what queueing it cost. */
final class QueuedJob
{
    public function __construct(
        public readonly Job $job,
        public readonly int $charged,
        public readonly int $credits,
        public readonly Priced $priced,
    ) {}

    public function id(): string
    {
        return $this->job->id;
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            Job::fromArray(Decode::map($body, 'job')),
            Decode::int($body, 'charged'),
            Decode::int($body, 'credits'),
            Priced::fromArray(Decode::map($body, 'priced')),
        );
    }
}
