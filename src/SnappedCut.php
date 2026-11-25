<?php

declare(strict_types=1);

namespace SnappedCut;

/**
 * The Snapped Cut client.
 *
 * **curl and nothing else.** No Composer dependencies, so it drops into a
 * legacy app without dragging a tree behind it. A client for the API should be
 * readable in one sitting.
 *
 *     $snapcut = new SnappedCut();                // reads SNAPCUT_KEY
 *     $job = $snapcut->render(file_get_contents('project.zip'));
 *     file_put_contents('out.webm', $snapcut->wait($job->id()));
 *
 * This covers the server API: the cloud render queue and the account behind it.
 * Everything else Snapped Cut does happens in a browser on WebCodecs, and no
 * SDK can make PHP do that.
 */
final class SnappedCut
{
    public const DEFAULT_BASE = 'https://snappedcut.com';

    public const VERSION = '0.2.0';

    private const USER_AGENT = 'snappedcut-php/' . self::VERSION;

    /**
     * How much of a bundle goes in one request.
     *
     * Cloudflare's free plan refuses a proxied request body over 100 MB and the
     * site is behind it, so a 512 MB bundle cannot be one POST however well it
     * streams. 64 MiB leaves room for headers and for a tighter cap on some
     * other proxy without making the chunk count silly.
     */
    public const CHUNK = 64 * 1024 * 1024;

    private const MAX_ATTEMPTS = 3;

    private string $key;

    private string $base;

    /** @var callable(string, string, ?string, array<string, string>, float): array{int, string} */
    private $transport;

    /**
     * @param callable(string, string, ?string, array<string, string>, float): array{int, string}|null $transport
     *        Injectable so the tests need no server.
     */
    private int $chunkSize;

    public function __construct(
        ?string $key = null,
        string $base = self::DEFAULT_BASE,
        private float $timeout = 120.0,
        ?callable $transport = null,
        /**
         * Lower this if your memory_limit is tight. A chunk is held in memory
         * while it is sent, so the ceiling is roughly memory_limit minus one
         * chunk -- and PHP's default limit is 128 MB, which a 64 MiB chunk plus
         * its request buffer will find.
         */
        ?int $chunkSize = null,
    ) {
        $this->key = $key ?? (string) getenv('SNAPCUT_KEY');
        $this->base = rtrim($base, '/');
        $this->transport = $transport ?? [$this, 'curlTransport'];
        $this->chunkSize = $chunkSize ?? self::CHUNK;
    }

    /** Never the key: a dump ends up in logs and stack traces. */
    public function __debugInfo(): array
    {
        return ['base' => $this->base, 'key' => $this->key === '' ? 'unset' : 'set'];
    }

    // -- plumbing ---------------------------------------------------------

    /**
     * @param  array<string, string> $headers
     * @return array{int, string}
     */
    private function curlTransport(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        float $timeout,
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SnappedCutError('Could not start a request.', 0);
        }

        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[] = "{$name}: {$value}";
        }

        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ];
        curl_setopt_array($handle, $options);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            // Never reached the server, so worth sending again.
            throw new SnappedCutError("Could not reach Snapped Cut: {$message}", 0);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, (string) $raw];
    }

    /**
     * @param  array<string, string> $extra  more headers, e.g. idempotency-key
     * @param  int|null              $answer a status that is an answer, not a refusal
     * @return array{int, string}
     */
    private function call(
        string $path,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = null,
        array $extra = [],
        ?int $answer = null,
    ): array {
        $headers = ['user-agent' => self::USER_AGENT] + $extra;
        if ($this->key !== '') {
            $headers['authorization'] = "Bearer {$this->key}";
        }
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        [$status, $raw] = ($this->transport)(
            $method,
            $this->base . $path,
            $body,
            $headers,
            $this->timeout,
        );

        if ($status >= 400 && $status !== $answer) {
            self::refuse($status, $raw);
        }

        return [$status, $raw];
    }

    private static function refuse(int $status, string $raw, string $who = 'Snapped Cut'): never
    {
        // An error body is not guaranteed to be JSON -- a proxy may answer
        // with HTML -- and the status is the part that matters either way.
        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $detail */
        $detail = is_array($decoded) ? $decoded : [];

        throw new SnappedCutError(
            Decode::str($detail, 'error', "{$who} said {$status}."),
            $status,
            Decode::int($detail, 'retryAfterMs'),
            Decode::int($detail, 'expected'),
            Decode::nullableStr($detail, 'code'),
        );
    }

    /**
     * @param  array<string, string> $extra
     * @return array<string, mixed>
     */
    private function json(
        string $path,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = null,
        array $extra = [],
        ?int $answer = null,
    ): array {
        [, $raw] = $this->call($path, $method, $body, $contentType, $extra, $answer);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            throw new SnappedCutError('Snapped Cut sent something that is not JSON.', 0);
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * A JSON request. An empty body goes as {}, which is what the API expects.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string> $extra
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = [], array $extra = [], ?int $answer = null): array
    {
        $encoded = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->json($path, $method, $encoded, 'application/json', $extra, $answer);
    }

    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function present(array $fields): array
    {
        return array_filter($fields, static fn(mixed $v): bool => $v !== null);
    }

    // -- the API ----------------------------------------------------------

    /** The account behind the key: who, and how many credits are left. */
    public function account(): Account
    {
        return Account::fromArray($this->json('/api/account'));
    }

    /**
     * Queue a render.
     *
     * There is no $seconds parameter and there should not be: the server reads
     * the timeline duration out of the bundle's own project.json and prices on
     * that. A duration supplied by the caller is a price supplied by the caller.
     * What it was charged for comes back in the Priced.
     *
     * A bundle over the chunk size is uploaded in pieces and resumes from the
     * *server's* count of what it holds, not ours.
     *
     * @param string|resource $bundle A zip, or a stream over one. Pass a stream
     *        for anything large: a string holds the whole file in memory, and a
     *        stream holds one chunk.
     */
    public function render(mixed $bundle, string $kind = 'cloud-render'): QueuedJob
    {
        $size = self::sizeOf($bundle);

        if ($size <= $this->chunkSize) {
            return QueuedJob::fromArray($this->json(
                '/api/jobs?' . http_build_query(['kind' => $kind]),
                'POST',
                $this->slice($bundle, 0, $size),
                'application/zip',
            ));
        }

        return $this->renderChunked($bundle, $kind, $size);
    }

    /** @param string|resource $bundle */
    private static function sizeOf(mixed $bundle): int
    {
        if (is_string($bundle)) {
            return strlen($bundle);
        }
        if (!is_resource($bundle)) {
            throw new SnappedCutError('A bundle is a string or a stream resource.', 0);
        }
        $stat = fstat($bundle);
        if ($stat === false) {
            throw new SnappedCutError('Could not measure that stream.', 0);
        }

        return (int) $stat['size'];
    }

    /**
     * One slice of the bundle.
     *
     * A stream is read rather than copied whole, which is the difference
     * between needing one chunk of memory and needing the entire file twice.
     *
     * @param string|resource $bundle
     */
    private function slice(mixed $bundle, int $from, int $to): string
    {
        if (is_string($bundle)) {
            return substr($bundle, $from, $to - $from);
        }
        $length = $to - $from;
        if ($length < 1) {
            return '';
        }
        if (fseek($bundle, $from) !== 0) {
            throw new SnappedCutError('That stream is not seekable.', 0);
        }
        $read = fread($bundle, $length);
        if ($read === false) {
            throw new SnappedCutError('Could not read that stream.', 0);
        }

        return $read;
    }

    /** @param string|resource $bundle */
    private function renderChunked(mixed $bundle, string $kind, int $size): QueuedJob
    {
        $upload = '';
        $offset = 0;
        $attempts = 0;

        while ($offset < $size) {
            $to = min($size, $offset + $this->chunkSize);
            $last = $to === $size;

            $query = ['kind' => $kind, 'offset' => $offset];
            if ($upload !== '') {
                $query['upload'] = $upload;
            }
            if ($last) {
                $query['final'] = '1';
            }

            try {
                $body = $this->json(
                    '/api/jobs?' . http_build_query($query),
                    'POST',
                    $this->slice($bundle, $offset, $to),
                    'application/zip',
                );

                if ($last) {
                    return QueuedJob::fromArray($body);
                }

                // The server's count, not ours: it is the one with the file.
                $upload = Decode::str($body, 'upload');
                $offset = Decode::int($body, 'received', $to);
                $attempts = 0;
            } catch (SnappedCutError $error) {
                if (++$attempts > self::MAX_ATTEMPTS) {
                    throw $error;
                }
                // Where the server says its copy ends. Believe it over our count.
                if ($error->status === 409) {
                    $offset = $error->expected;
                    continue;
                }
                // A refusal is a refusal -- no credits, bad bundle, bad key.
                // Only a lost connection (0) or a server fault is worth resending.
                if ($error->status !== 0 && $error->status < 500) {
                    throw $error;
                }
            }
        }

        throw new SnappedCutError('That upload ended without queueing a job.', 0);
    }

    public function job(string $id): Job
    {
        return Job::fromArray($this->getJob($id));
    }

    /** @return list<Job> */
    public function jobs(): array
    {
        return array_map(static fn(array $one): Job => Job::fromArray($one), $this->listJobs());
    }

    /** The rendered file. Throws unless the job is done. */
    public function output(string $id): string
    {
        return $this->downloadOutput($id);
    }

    /**
     * Wait for a job to finish, then hand back the file.
     *
     * @param (callable(Job): void)|null   $onState
     * @param (callable(float): void)|null $sleep   Injectable so tests are fast.
     */
    public function wait(
        string $id,
        float $timeout = 1800.0,
        ?callable $onState = null,
        ?callable $sleep = null,
    ): string {
        $typed = null;
        if ($onState !== null) {
            /** @param array<string, mixed> $raw */
            $typed = static function (array $raw) use ($onState): void {
                $onState(Job::fromArray($raw));
            };
        }
        $this->waitForJob($id, $timeout, $sleep, $typed);

        return $this->output($id);
    }

    // -- JSON jobs ----------------------------------------------------------
    //
    // These return the decoded JSON as arrays, so a field the API adds later
    // simply appears and never breaks a caller.

    /**
     * POST /api/jobs as JSON: a design, an uploaded bundle, or a tool over
     * uploaded media. An Idempotency-Key makes a retry return the first job
     * instead of charging twice.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed> job, charged, price, credits, idempotent
     */
    public function createJob(array $request, ?string $idempotencyKey = null): array
    {
        return $this->send(
            'POST',
            '/api/jobs',
            $request,
            $idempotencyKey === null ? [] : ['idempotency-key' => $idempotencyKey],
        );
    }

    /**
     * Up to 100 jobs in one request; `defaults` is merged under each, and each
     * item is answered on its own.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @param  array<string, mixed>|null   $defaults
     * @return array<string, mixed>
     */
    public function createBatch(array $jobs, ?array $defaults = null): array
    {
        return $this->send('POST', '/api/jobs/batch', self::present(['jobs' => $jobs, 'defaults' => $defaults]));
    }

    /** @return array<string, mixed> */
    public function getJob(string $id): array
    {
        return $this->json('/api/jobs/' . rawurlencode($id));
    }

    /** @return list<array<string, mixed>> */
    public function listJobs(): array
    {
        return Decode::rows($this->json('/api/jobs'), 'jobs');
    }

    /** One output's bytes; a job with several outputs numbers them from 0. */
    public function downloadOutput(string $id, int $index = 0): string
    {
        $query = $index > 0 ? "?index={$index}" : '';
        [, $raw] = $this->call('/api/jobs/' . rawurlencode($id) . '/output' . $query);

        return $raw;
    }

    /**
     * Poll until the job is done (returns it) or failed (throws its error).
     *
     * Backs off from 1 s by 1.5x to 15 s rather than polling at a fixed
     * interval: a render is minutes, and the server has a rate limit a busy
     * loop will find.
     *
     * @param  (callable(float): void)|null                $sleep   Injectable so tests are fast.
     * @param  (callable(array<string, mixed>): void)|null $onState
     * @return array<string, mixed>
     */
    public function waitForJob(
        string $id,
        float $timeout = 1800.0,
        ?callable $sleep = null,
        ?callable $onState = null,
    ): array {
        $sleep ??= static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };

        $deadline = microtime(true) + $timeout;
        $delay = 1.0;

        while (true) {
            $job = $this->getJob($id);
            if ($onState !== null) {
                $onState($job);
            }

            $state = Decode::str($job, 'state');
            if ($state === 'done') {
                return $job;
            }
            // The credits are already back -- the server refunds a failure -- so
            // this is a plain error, not something the caller has to reconcile.
            if ($state === 'failed') {
                throw new SnappedCutError(Decode::nullableStr($job, 'error') ?? 'The render failed.', 500);
            }
            if (microtime(true) > $deadline) {
                throw new SnappedCutError("Job {$id} is still {$state}.", 504);
            }

            $sleep($delay);
            $delay = min($delay * 1.5, 15.0);
        }
    }

    // -- designs ------------------------------------------------------------

    /**
     * Compile a design to the editor's project.json without rendering it.
     *
     * @param  array<string, mixed>      $design
     * @param  array<string, mixed>|null $vars
     * @return array<string, mixed>
     */
    public function compileDesign(array $design, ?array $vars = null): array
    {
        return $this->send('POST', '/api/designs', self::present(['design' => $design, 'vars' => $vars]));
    }

    /**
     * @param  array<string, mixed> $design
     * @param  array<string, mixed> $options format, preset, outputs, destination, ...
     * @return array<string, mixed>
     */
    public function renderDesign(array $design, array $options = [], ?string $idempotencyKey = null): array
    {
        return $this->createJob(['design' => $design] + $options, $idempotencyKey);
    }

    // -- uploads ------------------------------------------------------------

    /**
     * Upload media once, for use by id in designs, tools and jobs: register it,
     * PUT it in chunks under the server's per-request cap, then confirm it.
     *
     * @param  string|resource      $data the bytes, or a stream (one chunk in memory)
     * @return array<string, mixed> the asset
     */
    public function upload(mixed $data, string $contentType, ?string $name = null): array
    {
        $size = self::sizeOf($data);
        $made = $this->send('POST', '/api/uploads', self::present(['contentType' => $contentType, 'name' => $name]));
        $upload = Decode::map($made, 'upload');
        $url = Decode::str($upload, 'url');
        if (str_starts_with($url, '/')) {
            $url = $this->base . $url;
        }
        $step = max(1, Decode::int($upload, 'maxChunkBytes', $this->chunkSize));

        $offset = 0;
        do {
            $to = min($size, $offset + $step);
            // The upload URL carries its own permission; the API key is not sent to it.
            [$status, $raw] = ($this->transport)(
                'PUT',
                "{$url}&offset={$offset}",
                $this->slice($data, $offset, $to),
                ['user-agent' => self::USER_AGENT, 'content-type' => $contentType],
                $this->timeout,
            );
            if ($status >= 400) {
                self::refuse($status, $raw, 'The upload');
            }
            $offset = $to;
        } while ($offset < $size);

        $confirm = '/api/uploads/' . rawurlencode(Decode::str($made, 'assetId')) . '/confirm';

        return Decode::map($this->send('POST', $confirm), 'asset');
    }

    /** @return array<string, mixed> */
    public function getAsset(string $id): array
    {
        return Decode::map($this->json('/api/uploads/' . rawurlencode($id)), 'asset');
    }

    public function deleteAsset(string $id): void
    {
        $this->call('/api/uploads/' . rawurlencode($id), 'DELETE');
    }

    // -- meta and usage -------------------------------------------------------

    /**
     * Every operation, the render parameters, and the presets.
     *
     * @return array<string, mixed>
     */
    public function operations(): array
    {
        return $this->json('/api/operations');
    }

    /**
     * @param  string|null          $groupBy day, week, month, endUser, key or operation
     * @return array<string, mixed>
     */
    public function usage(?int $from = null, ?int $to = null, ?string $endUserId = null, ?string $groupBy = null): array
    {
        // http_build_query drops nulls, which is exactly "omit absent params".
        $query = http_build_query(['from' => $from, 'to' => $to, 'endUserId' => $endUserId, 'groupBy' => $groupBy]);

        return $this->json('/api/usage' . ($query === '' ? '' : "?{$query}"));
    }

    /**
     * Delete one end user's renders and uploads, and their id from everything kept.
     *
     * @return array<string, mixed>
     */
    public function deleteEndUser(string $endUserId): array
    {
        return $this->json('/api/usage?endUserId=' . rawurlencode($endUserId), 'DELETE');
    }

    // -- embed ----------------------------------------------------------------

    /**
     * Mint an embed session token for one of your end users. Server side only.
     *
     * @param  array<string, mixed>|null $features
     * @return array<string, mixed>      token, sessionId, expiresAt, maxCredits, mode
     */
    public function createEmbedToken(
        string $pk,
        string $endUserId,
        ?int $maxCredits = null,
        ?int $ttlSeconds = null,
        ?string $origin = null,
        ?array $features = null,
    ): array {
        return $this->send('POST', '/api/embed/tokens', self::present([
            'pk' => $pk,
            'endUserId' => $endUserId,
            'maxCredits' => $maxCredits,
            'ttlSeconds' => $ttlSeconds,
            'origin' => $origin,
            'features' => $features,
        ]));
    }

    // -- storage destinations -------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listDestinations(): array
    {
        return Decode::rows($this->json('/api/destinations'), 'destinations');
    }

    /**
     * @param  array<string, mixed> $input name, endpoint, region?, bucket, prefix?, accessKeyId, secretAccessKey, pathStyle?
     * @return array<string, mixed>
     */
    public function createDestination(array $input): array
    {
        return Decode::map($this->send('POST', '/api/destinations', $input), 'destination');
    }

    /** @return array<string, mixed> */
    public function getDestination(string $id): array
    {
        return Decode::map($this->json('/api/destinations/' . rawurlencode($id)), 'destination');
    }

    /**
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateDestination(string $id, array $patch): array
    {
        return Decode::map($this->send('PATCH', '/api/destinations/' . rawurlencode($id), $patch), 'destination');
    }

    public function deleteDestination(string $id): void
    {
        $this->call('/api/destinations/' . rawurlencode($id), 'DELETE');
    }

    /**
     * Write a test object. A failed write (422) is an answer `{ok: false, ...}`,
     * not an exception.
     *
     * @return array<string, mixed>
     */
    public function testDestination(string $id): array
    {
        return $this->send('POST', '/api/destinations/' . rawurlencode($id) . '/test', answer: 422);
    }

    /** @return array<string, mixed> method, url, key, expiresAt */
    public function presignDestination(string $id, string $key, ?int $expiresIn = null): array
    {
        return $this->send(
            'POST',
            '/api/destinations/' . rawurlencode($id) . '/presign',
            self::present(['key' => $key, 'expiresIn' => $expiresIn]),
        );
    }

    // -- webhook endpoints ----------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listWebhooks(): array
    {
        return Decode::rows($this->json('/api/webhooks'), 'webhooks');
    }

    /**
     * @param  list<string>|null    $events
     * @return array<string, mixed> webhook and secret; the secret comes back this once
     */
    public function createWebhook(string $url, ?array $events = null): array
    {
        return $this->send('POST', '/api/webhooks', self::present(['url' => $url, 'events' => $events]));
    }

    /** @return array<string, mixed> */
    public function getWebhook(string $id): array
    {
        return Decode::map($this->json('/api/webhooks/' . rawurlencode($id)), 'webhook');
    }

    /**
     * @param  list<string>|null    $events
     * @return array<string, mixed>
     */
    public function updateWebhook(string $id, ?string $url = null, ?array $events = null, ?bool $enabled = null): array
    {
        $patch = self::present(['url' => $url, 'events' => $events, 'enabled' => $enabled]);

        return Decode::map($this->send('PATCH', '/api/webhooks/' . rawurlencode($id), $patch), 'webhook');
    }

    public function deleteWebhook(string $id): void
    {
        $this->call('/api/webhooks/' . rawurlencode($id), 'DELETE');
    }

    /** A new signing secret; the old one stops working. */
    public function rotateWebhook(string $id): string
    {
        return Decode::str($this->send('POST', '/api/webhooks/' . rawurlencode($id) . '/rotate'), 'secret');
    }

    /** @return list<array<string, mixed>> */
    public function webhookDeliveries(string $id, ?int $limit = null): array
    {
        $query = $limit === null ? '' : "?limit={$limit}";

        return Decode::rows($this->json('/api/webhooks/' . rawurlencode($id) . '/deliveries' . $query), 'deliveries');
    }

    /** @return array<string, mixed> eventId */
    public function testWebhook(string $id): array
    {
        return $this->send('POST', '/api/webhooks/' . rawurlencode($id) . '/test');
    }

    /**
     * Check an `X-Snappedcut-Signature` header (`t=<epoch ms>,v1=<hex>`)
     * against the raw body, before it is decoded.
     *
     * False when t is missing or more than five minutes off, when v1 is not 64
     * lowercase hex characters, or when it does not match.
     */
    public static function verifyWebhook(string $secret, string $rawBody, ?string $signatureHeader, ?int $nowMs = null): bool
    {
        $parts = [];
        foreach (explode(',', $signatureHeader ?? '') as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$name] = $value;
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (preg_match('/^\d{1,16}$/', $t) !== 1 || preg_match('/^[0-9a-f]{64}$/', $v1) !== 1) {
            return false;
        }
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        if (abs($now - (int) $t) > 300_000) {
            return false;
        }

        // hash_equals is constant time; a plain === would leak the prefix that matched.
        return hash_equals(hash_hmac('sha256', "{$t}.{$rawBody}", $secret), $v1);
    }
}
