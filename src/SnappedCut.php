<?php

declare(strict_types=1);

namespace SnappedCut;

/**
 * The Snapped Cut client.
 *
 * **curl and nothing else.** No Composer dependencies, so it drops into a
 * legacy app without dragging a tree behind it. The API is five endpoints; a
 * client for it should be readable in one sitting.
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

    /** @return array{int, string} */
    private function call(
        string $path,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = null,
    ): array {
        $headers = ['user-agent' => 'snappedcut-php/0.1.0'];
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

        if ($status >= 400) {
            // An error body is not guaranteed to be JSON -- a proxy may answer
            // with HTML -- and the status is the part that matters either way.
            $decoded = json_decode($raw, true);
            /** @var array<string, mixed> $detail */
            $detail = is_array($decoded) ? $decoded : [];

            throw new SnappedCutError(
                Decode::str($detail, 'error', "Snapped Cut said {$status}."),
                $status,
                Decode::int($detail, 'retryAfterMs'),
                Decode::int($detail, 'expected'),
            );
        }

        return [$status, $raw];
    }

    /** @return array<string, mixed> */
    private function json(
        string $path,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = null,
    ): array {
        [, $raw] = $this->call($path, $method, $body, $contentType);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            throw new SnappedCutError('Snapped Cut sent something that is not JSON.', 0);
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
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
        return Job::fromArray($this->json('/api/jobs/' . rawurlencode($id)));
    }

    /** @return list<Job> */
    public function jobs(): array
    {
        $rows = Decode::rows($this->json('/api/jobs'), 'jobs');

        return array_map(static fn(array $one): Job => Job::fromArray($one), $rows);
    }

    /** The rendered file. Throws unless the job is done. */
    public function output(string $id): string
    {
        [, $raw] = $this->call('/api/jobs/' . rawurlencode($id) . '/output');

        return $raw;
    }

    /**
     * Wait for a job to finish, then hand back the file.
     *
     * Polls with a backoff rather than at a fixed interval: a render is minutes,
     * so a one-second poll spends most of its requests learning nothing, and the
     * server has a rate limit a busy loop will find.
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
        $sleep ??= static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };

        $deadline = microtime(true) + $timeout;
        $delay = 1.0;

        while (true) {
            $job = $this->job($id);
            if ($onState !== null) {
                $onState($job);
            }

            if ($job->state === 'done') {
                return $this->output($id);
            }
            // The credits are already back -- the server refunds a failure -- so
            // this is a plain error, not something the caller has to reconcile.
            if ($job->state === 'failed') {
                throw new SnappedCutError($job->error ?? 'The render failed.', 500);
            }
            if (microtime(true) > $deadline) {
                throw new SnappedCutError("Job {$id} is still {$job->state}.", 504);
            }

            $sleep($delay);
            $delay = min($delay * 1.5, 15.0);
        }
    }
}
