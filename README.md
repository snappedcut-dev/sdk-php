# sdk-php

PHP client for the [Snapped Cut](https://snappedcut.com) cloud render API.
**No Composer dependencies** — curl and json, both of which you already have —
so it drops into a legacy app without dragging a tree behind it.

```sh
composer require snappedcut/sdk
```

## Quick start

```php
use SnappedCut\SnappedCut;

$snapcut = new SnappedCut();                                  // reads SNAPCUT_KEY
$clip = $snapcut->upload(fopen('intro.webm', 'rb'), 'video/webm', 'intro.webm');
$design = ['width' => 1920, 'height' => 1080, 'clips' => [
    ['type' => 'video', 'asset' => $clip['id']],
    ['type' => 'text', 'text' => 'Hello', 'start' => 0, 'duration' => 3],
]];
$created = $snapcut->renderDesign($design, ['outputs' => [
    ['format' => 'mp4', 'preset' => 'landscape'],
    ['format' => 'webm', 'preset' => 'vertical'],
]], 'order-42');  // Idempotency-Key: a retry returns this job, not a second charge
$job = $snapcut->waitForJob($created['job']['id']);
file_put_contents('out.mp4', $snapcut->downloadOutput($job['id'], 0));
file_put_contents('out.webm', $snapcut->downloadOutput($job['id'], 1));
```

Presets: `source`, `vertical`, `square`, `portrait`, `landscape`, `hd720`,
`uhd2160`. The JSON calls return the decoded response as arrays, so a field the
API adds later just appears.

### Verifying webhooks

```php
$ok = SnappedCut::verifyWebhook(
    getenv('SNAPCUT_WEBHOOK_SECRET'),
    file_get_contents('php://input'),               // the raw body, before decoding
    $_SERVER['HTTP_X_SNAPPEDCUT_SIGNATURE'] ?? null,
);
if (!$ok) {
    http_response_code(400);
    exit;
}
```

The header is `t=<epoch ms>,v1=<hex HMAC-SHA256 of "t.body">`; anything more
than five minutes old is refused, and the comparison is constant time.

### Rendering a bundle

```php
$job = $snapcut->render(fopen('project.zip', 'rb'));
printf("charged %d credits for %gs\n", $job->charged, $job->priced->seconds);

file_put_contents('out.webm', $snapcut->wait($job->id()));
```

Get a key from [your account page](https://snappedcut.com/account) — it looks
like `sk_snapcut_…`. Keep it out of your document root; it spends credits.

## API

| | |
| --- | --- |
| `new SnappedCut($key, $base, $timeout, $transport, $chunkSize)` | `$key` falls back to the `SNAPCUT_KEY` environment variable. All optional. |
| `account()` | `Account` — who the key belongs to, credits left, the price list. |
| `render($bundle, $kind)` | Queue a render. `$bundle` is a string **or a stream**. |
| `job($id)` / `jobs()` | One `Job`, or every job on the account. |
| `output($id)` | The rendered bytes. Throws unless the job is done. |
| `wait($id, $timeout, $onState, $sleep)` | Polls to completion, returns the bytes. |
| `createJob($request, $idempotencyKey)` / `createBatch($jobs, $defaults)` | A JSON job (design, uploaded bundle, or tool), or up to 100 at once. |
| `getJob($id)` / `listJobs()` / `downloadOutput($id, $index)` | The JSON job(s); one output's bytes. |
| `waitForJob($id, $timeout, $sleep)` | Polls from 1 s, x1.5, capped at 15 s; returns the job on `done`, throws on `failed`. |
| `compileDesign($design, $vars)` / `renderDesign($design, $options, $idempotencyKey)` | A design to project.json, or straight to a job. |
| `upload($data, $contentType, $name)` / `getAsset($id)` / `deleteAsset($id)` | Direct uploads in chunks; the key is never sent to the upload URL. |
| `operations()` / `usage($from, $to, $endUserId, $groupBy)` / `deleteEndUser($id)` | What the API can do; what you spent. |
| `createEmbedToken($pk, $endUserId, ...)` | An embed session token for one of your users. |
| `listDestinations()`, `createDestination()`, `getDestination()`, `updateDestination()`, `deleteDestination()`, `testDestination()`, `presignDestination()` | Storage destinations. A failed test is an answer (`ok: false`), not a throw. |
| `listWebhooks()`, `createWebhook()`, `getWebhook()`, `updateWebhook()`, `deleteWebhook()`, `rotateWebhook()`, `webhookDeliveries()`, `testWebhook()` | Webhook endpoints. |
| `SnappedCut::verifyWebhook($secret, $rawBody, $header, $nowMs)` | Static. True only for a fresh, matching signature. |

## Pass a stream for anything large

This is the one thing worth knowing before you ship it. A **string** bundle
holds the whole file in memory; a **stream** holds one chunk:

```php
$snapcut->render(fopen('project.zip', 'rb'));       // one chunk in memory
$snapcut->render(file_get_contents('project.zip')); // the whole file, twice
```

PHP's default `memory_limit` is 128 MB, and a 64 MiB chunk plus the request
buffer will find it. If your limit is tighter, lower the chunk size rather than
raising the limit:

```php
$snapcut = new SnappedCut(chunkSize: 8 * 1024 * 1024);
```

## Large bundles resume themselves

A bundle over the chunk size uploads in pieces, and if the connection drops it
carries on from **the server's** count of what it holds, not its own — a `409`
carries the expected offset and the client believes it. Three attempts per
chunk; a `4xx` that is not `409` is a real refusal (no credits, bad bundle, bad
key) and stops immediately rather than retrying its way through your balance.

## You cannot set the price

There is no `$seconds` parameter, deliberately. The server reads the timeline
duration out of the bundle's own `project.json` and prices on that, because a
duration supplied by the caller is a price supplied by the caller. What you were
actually charged is in `charged`, with the server's reasoning in `priced`.

## Errors

Every failure throws `SnappedCutError` with the HTTP `status` on it, plus
`retryAfterMs` and `errorCode` (the body's `code`; PHP's Exception already owns
`$code`). The two failures you actually branch on have methods so you need not
parse a message (`isOutOfCredits()` and `isRateLimited()` are the same checks
under the names every Snapped Cut SDK uses):

```php
use SnappedCut\SnappedCutError;

try {
    $snapcut->render($bundle);
} catch (SnappedCutError $error) {
    if ($error->outOfCredits()) {            // 402
        topUp();
    } elseif ($error->rateLimited()) {       // 429
        usleep($error->retryAfterMs * 1000);
    } elseif ($error->status === 0) {        // never reached the server
        retry();
    } else {
        throw $error;
    }
}
```

A job that fails server-side has **already been refunded** by the time `wait()`
throws, so there is nothing to reconcile.

`var_dump()` of a client never shows the key — a dump ends up in logs and stack
traces.

## Every response field is checked

`json_decode` returns `mixed`, and the server is across a network, so every
field is a claim rather than a fact. Casting `mixed` straight to `int` hides a
malformed or hostile response behind a plausible-looking `0`, so `Decode`
narrows each field by type with a stated fallback instead. That is why this
package is clean at **PHPStan level 9**, the strictest there is.

## What this is not

Snapped Cut edits video **on the device**: decode, cut, filter and encode all
run in a browser on WebCodecs. There is no API for them and there cannot be —
no SDK can make PHP do that. What this covers is the one thing that *does*
happen on our machines: a cloud render, which is opt-in and costs credits.

MIT
