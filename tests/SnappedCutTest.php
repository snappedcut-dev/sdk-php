<?php

declare(strict_types=1);

namespace SnappedCut\Tests;

use PHPUnit\Framework\TestCase;
use SnappedCut\SnappedCut;
use SnappedCut\SnappedCutError;

final class SnappedCutTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    private array $calls = [];

    /**
     * A transport that records what it was asked and answers from a script.
     *
     * @param list<array{0: int, 1: string}> $replies
     */
    private function transport(array $replies): callable
    {
        $this->calls = [];
        $queue = $replies;

        return function (
            string $method,
            string $url,
            ?string $body,
            array $headers,
            float $timeout,
        ) use (&$queue): array {
            $this->calls[] = compact('method', 'url', 'body', 'headers');
            if ($queue === []) {
                self::fail("unexpected request: {$url}");
            }

            return array_shift($queue);
        };
    }

    private const QUEUED = '{"job":{"id":"j1","kind":"cloud-render","state":"queued","seconds":4},'
        . '"charged":8,"credits":92,"priced":{"seconds":4,"project":"p","layers":2}}';

    private static function job(string $state, ?string $error = null): string
    {
        $errorJson = $error === null ? 'null' : '"' . $error . '"';

        return '{"id":"j1","kind":"cloud-render","state":"' . $state . '",'
            . '"error":' . $errorJson . ',"seconds":4}';
    }

    public function testKeyIsSentAsABearerToken(): void
    {
        $snapcut = new SnappedCut('sk_snapcut_test', transport: $this->transport([
            [200, '{"id":"a","email":"e@x.com","credits":50,"prices":{}}'],
        ]));
        $account = $snapcut->account();

        self::assertSame('e@x.com', $account->email);
        self::assertSame('Bearer sk_snapcut_test', $this->calls[0]['headers']['authorization']);
        self::assertSame('https://snappedcut.com/api/account', $this->calls[0]['url']);
    }

    public function testDebugInfoNeverContainsTheKey(): void
    {
        // A dump ends up in logs and stack traces.
        $dump = print_r((new SnappedCut('sk_snapcut_secret'))->__debugInfo(), true);
        self::assertStringNotContainsString('sk_snapcut_secret', $dump);
    }

    public function testATrailingSlashOnTheBaseDoesNotDoubleUp(): void
    {
        $snapcut = new SnappedCut('k', 'http://localhost:3000/', transport: $this->transport([
            [200, '{}'],
        ]));
        $snapcut->account();

        self::assertSame('http://localhost:3000/api/account', $this->calls[0]['url']);
    }

    public function testA402IsCatchableWithoutParsingAMessage(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [402, '{"error":"Not enough credits."}'],
        ]));

        try {
            $snapcut->account();
            self::fail('expected a refusal');
        } catch (SnappedCutError $error) {
            self::assertTrue($error->outOfCredits());
            self::assertSame(402, $error->status);
        }
    }

    public function testA429ReportsItselfAsRateLimited(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [429, '{"error":"Slow down.","retryAfterMs":2000}'],
        ]));

        try {
            $snapcut->account();
            self::fail('expected a refusal');
        } catch (SnappedCutError $error) {
            self::assertTrue($error->rateLimited());
            self::assertSame(2000, $error->retryAfterMs);
        }
    }

    public function testANonJsonErrorBodyStillThrowsCleanly(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[500, '<html>oops']]));

        $this->expectException(SnappedCutError::class);
        $snapcut->account();
    }

    public function testRenderNeverSendsADuration(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, self::QUEUED]]));
        $queued = $snapcut->render('zipbytes');

        self::assertSame(8, $queued->charged);
        self::assertSame(4.0, $queued->priced->seconds);
        // A caller-supplied duration would be a caller-supplied price.
        self::assertStringNotContainsString('seconds', $this->calls[0]['url']);
    }

    public function testASmallBundleIsOneRequest(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, self::QUEUED]]));
        $snapcut->render(str_repeat('x', 1024));

        self::assertCount(1, $this->calls);
        self::assertStringNotContainsString('offset', $this->calls[0]['url']);
    }

    public function testALargeBundleIsChunkedAndTheLastChunkIsFinal(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"upload":"u1","received":1024}'],
            [200, self::QUEUED],
        ]), chunkSize: 1024);
        $snapcut->render(str_repeat("\0", 1040));

        self::assertCount(2, $this->calls);
        self::assertStringContainsString('offset=0', $this->calls[0]['url']);
        self::assertStringContainsString('upload=u1', $this->calls[1]['url']);
        self::assertStringContainsString('final=1', $this->calls[1]['url']);
    }

    public function testA409ResumesFromTheOffsetTheServerReports(): void
    {
        // Our count and the server's disagreed. The server has the file.
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"upload":"u1","received":10}'],
            [409, '{"error":"offset","expected":1024}'],
            [200, self::QUEUED],
        ]), chunkSize: 1024);
        $snapcut->render(str_repeat("\0", 1040));

        self::assertStringContainsString('offset=1024', $this->calls[2]['url']);
    }

    public function testARefusalMidUploadStopsRatherThanRetrying(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"upload":"u1","received":10}'],
            [402, '{"error":"Not enough credits."}'],
        ]), chunkSize: 1024);

        try {
            $snapcut->render(str_repeat("\0", 1040));
            self::fail('expected a refusal');
        } catch (SnappedCutError $error) {
            self::assertTrue($error->outOfCredits());
            self::assertCount(2, $this->calls);
        }
    }

    public function testWaitPollsUntilDoneThenReturnsTheBytes(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, self::job('running')],
            [200, self::job('done')],
            [200, 'MOVIE'],
        ]));

        $seen = [];
        $file = $snapcut->wait(
            'j1',
            onState: static function ($job) use (&$seen): void {
                $seen[] = $job->state;
            },
            sleep: static function (float $s): void {},
        );

        self::assertSame(['running', 'done'], $seen);
        self::assertSame('MOVIE', $file);
    }

    public function testAFailedJobThrowsRatherThanReturningAnEmptyFile(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, self::job('failed', 'decode failed')],
        ]));

        $this->expectExceptionMessage('decode failed');
        $snapcut->wait('j1', sleep: static function (float $s): void {});
    }

    public function testWaitGivesUpAtTheTimeoutWithA504(): void
    {
        $replies = array_fill(0, 20, [200, self::job('queued')]);
        $snapcut = new SnappedCut('k', transport: $this->transport($replies));

        try {
            $snapcut->wait('j1', timeout: -1, sleep: static function (float $s): void {});
            self::fail('expected a timeout');
        } catch (SnappedCutError $error) {
            self::assertSame(504, $error->status);
        }
    }

    public function testAJobIdIsEscapedIntoThePath(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, self::job('done')]]));
        $snapcut->job('a/../b');

        self::assertStringContainsString('a%2F..%2Fb', $this->calls[0]['url']);
    }

    public function testJobsUnwrapsTheEnvelope(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"jobs":[' . self::job('done') . ',' . self::job('queued') . ']}'],
        ]));
        $jobs = $snapcut->jobs();

        self::assertSame(['done', 'queued'], array_map(static fn($j) => $j->state, $jobs));
    }

    public function testAStreamIsChunkedWithoutHoldingTheWholeFile(): void
    {
        // The point of the stream path: one chunk in memory, not the whole file.
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat("\0", 2048));

        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"upload":"u1","received":1024}'],
            [200, self::QUEUED],
        ]), chunkSize: 1024);
        $queued = $snapcut->render($stream);

        self::assertSame('j1', $queued->id());
        self::assertCount(2, $this->calls);
        self::assertSame(1024, strlen((string) $this->calls[0]['body']));
    }

    public function testARefusedBundleTypeFailsBeforeAnyRequest(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([]));

        $this->expectException(SnappedCutError::class);
        /** @phpstan-ignore-next-line - deliberately the wrong type */
        $snapcut->render(42);
    }

    public function testJobsToleratesAnEmptyAccount(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, '{}']]));
        self::assertSame([], $snapcut->jobs());
    }
}
