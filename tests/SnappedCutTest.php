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

    // -- 0.2.0: JSON jobs, uploads, webhooks ------------------------------

    private const WEBHOOK_SECRET = 'whsec_snapcut_test';

    private const WEBHOOK_BODY = '{"id":"evt_1","type":"job.succeeded"}';

    private const WEBHOOK_HEADER = 't=1760000000000,v1=54c2adf2a351242de19b156794d65c56bebf9fc56cadac990c56b979a7a61ab2';

    public function testTheFixedWebhookVector(): void
    {
        self::assertTrue(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY, self::WEBHOOK_HEADER, 1760000001000));
        // Outside five minutes.
        self::assertFalse(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY, self::WEBHOOK_HEADER, 1760000400000));
        // One trailing space is a different body.
        self::assertFalse(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY . ' ', self::WEBHOOK_HEADER, 1760000001000));
    }

    public function testAMalformedSignatureIsFalseNotAnError(): void
    {
        $v1Only = substr(self::WEBHOOK_HEADER, strlen('t=1760000000000,'));
        self::assertFalse(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY, $v1Only, 1760000001000));
        self::assertFalse(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY, strtoupper(self::WEBHOOK_HEADER), 1760000001000));
        self::assertFalse(SnappedCut::verifyWebhook(self::WEBHOOK_SECRET, self::WEBHOOK_BODY, null, 1760000001000));
    }

    public function testCreateJobSendsTheKeyTheIdempotencyKeyAndJson(): void
    {
        $snapcut = new SnappedCut('sk_snapcut_test', transport: $this->transport([
            [201, '{"job":{"id":"j1","state":"queued","newField":1},"charged":3,"price":3,"credits":97,"idempotent":false}'],
        ]));
        $created = $snapcut->createJob(['design' => ['clips' => []], 'format' => 'mp4'], 'order-42');

        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://snappedcut.com/api/jobs', $call['url']);
        self::assertSame('Bearer sk_snapcut_test', $call['headers']['authorization']);
        self::assertSame('order-42', $call['headers']['idempotency-key']);
        self::assertSame('application/json', $call['headers']['content-type']);
        self::assertSame('snappedcut-php/0.2.0', $call['headers']['user-agent']);
        self::assertSame(['design' => ['clips' => []], 'format' => 'mp4'], json_decode((string) $call['body'], true));
        self::assertSame(3, $created['charged']);
    }

    public function testCreateJobWithoutAKeySendsNoIdempotencyHeader(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[201, '{}']]));
        $snapcut->createJob(['bundle' => 'ast_1']);

        self::assertArrayNotHasKey('idempotency-key', $this->calls[0]['headers']);
    }

    public function testA402FromCreateJobIsOutOfCredits(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [402, '{"error":"Not enough credits.","retryAfterMs":0}'],
        ]));

        try {
            $snapcut->createJob(['bundle' => 'ast_1']);
            self::fail('expected a refusal');
        } catch (SnappedCutError $error) {
            self::assertTrue($error->isOutOfCredits());
            self::assertFalse($error->isRateLimited());
            self::assertSame(402, $error->status);
            self::assertSame('Not enough credits.', $error->getMessage());
            self::assertSame(0, $error->retryAfterMs);
            self::assertNull($error->errorCode);
        }
    }

    public function testAnErrorCodeIsCarried(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [429, '{"error":"Slow down.","retryAfterMs":1500,"code":"rate_limited"}'],
        ]));

        try {
            $snapcut->account();
            self::fail('expected a refusal');
        } catch (SnappedCutError $error) {
            self::assertTrue($error->isRateLimited());
            self::assertSame('rate_limited', $error->errorCode);
        }
    }

    public function testUploadPutsTwoChunksWithoutTheKeyThenConfirms(): void
    {
        $snapcut = new SnappedCut('sk_snapcut_test', transport: $this->transport([
            [201, '{"assetId":"ast_1","upload":{"method":"PUT","url":"https://up.example.test/api/uploads/ast_1?token=tok","maxChunkBytes":4}}'],
            [200, '{"assetId":"ast_1","received":4}'],
            [200, '{"assetId":"ast_1","received":6}'],
            [200, '{"asset":{"id":"ast_1","confirmed":true}}'],
        ]));
        $asset = $snapcut->upload('abcdef', 'video/webm', 'clip.webm');

        self::assertCount(4, $this->calls);
        self::assertSame(['contentType' => 'video/webm', 'name' => 'clip.webm'], json_decode((string) $this->calls[0]['body'], true));
        self::assertSame('PUT', $this->calls[1]['method']);
        self::assertSame('https://up.example.test/api/uploads/ast_1?token=tok&offset=0', $this->calls[1]['url']);
        self::assertSame('abcd', $this->calls[1]['body']);
        self::assertSame('https://up.example.test/api/uploads/ast_1?token=tok&offset=4', $this->calls[2]['url']);
        self::assertSame('ef', $this->calls[2]['body']);
        // The upload URL carries its own permission.
        self::assertArrayNotHasKey('authorization', $this->calls[1]['headers']);
        self::assertArrayNotHasKey('authorization', $this->calls[2]['headers']);
        self::assertSame('https://snappedcut.com/api/uploads/ast_1/confirm', $this->calls[3]['url']);
        self::assertSame('{}', $this->calls[3]['body']);
        self::assertTrue($asset['confirmed']);
    }

    public function testUploadReadsAStreamAChunkAtATime(): void
    {
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'abcdef');

        $snapcut = new SnappedCut('k', transport: $this->transport([
            [201, '{"assetId":"ast_2","upload":{"url":"https://up.example.test/u?token=x","maxChunkBytes":4}}'],
            [200, '{}'],
            [200, '{}'],
            [200, '{"asset":{"id":"ast_2"}}'],
        ]));
        $snapcut->upload($stream, 'video/webm');

        self::assertSame('abcd', $this->calls[1]['body']);
        self::assertStringEndsWith('&offset=4', $this->calls[2]['url']);
        self::assertSame('ef', $this->calls[2]['body']);
    }

    public function testWaitForJobReturnsTheJobOnDoneAndBacksOff(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, self::job('queued')],
            [200, self::job('running')],
            [200, self::job('done')],
        ]));

        $slept = [];
        $job = $snapcut->waitForJob('j1', sleep: static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });

        self::assertSame('done', $job['state']);
        self::assertSame([1.0, 1.5], $slept);
        self::assertCount(3, $this->calls);
    }

    public function testWaitForJobThrowsTheJobErrorOnFailed(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, self::job('failed', 'Decode failed.')],
        ]));

        $this->expectException(SnappedCutError::class);
        $this->expectExceptionMessage('Decode failed.');
        $snapcut->waitForJob('j1', sleep: static function (float $s): void {});
    }

    public function testDownloadOutputSendsAnIndexOnlyAboveZero(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, 'A'], [200, 'B']]));

        self::assertSame('A', $snapcut->downloadOutput('j1'));
        self::assertSame('B', $snapcut->downloadOutput('j1', 1));
        self::assertStringEndsWith('/api/jobs/j1/output', $this->calls[0]['url']);
        self::assertStringEndsWith('/api/jobs/j1/output?index=1', $this->calls[1]['url']);
    }

    public function testUsageOmitsAbsentParams(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, '{}']]));
        $snapcut->usage(from: 10, groupBy: 'day');

        self::assertStringEndsWith('/api/usage?from=10&groupBy=day', $this->calls[0]['url']);
    }

    public function testAFailedDestinationTestIsAnAnswer(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [422, '{"ok":false,"key":"k","error":"Denied."}'],
        ]));

        self::assertFalse($snapcut->testDestination('d1')['ok']);
    }

    public function testUpdateWebhookSendsOnlyWhatChanged(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([
            [200, '{"webhook":{"id":"w1","enabled":false}}'],
        ]));
        $webhook = $snapcut->updateWebhook('w1', enabled: false);

        self::assertSame('PATCH', $this->calls[0]['method']);
        self::assertSame('{"enabled":false}', $this->calls[0]['body']);
        self::assertFalse($webhook['enabled']);
    }

    public function testRotateWebhookReturnsTheNewSecret(): void
    {
        $snapcut = new SnappedCut('k', transport: $this->transport([[200, '{"secret":"whsec_new"}']]));

        self::assertSame('whsec_new', $snapcut->rotateWebhook('w1'));
    }
}
