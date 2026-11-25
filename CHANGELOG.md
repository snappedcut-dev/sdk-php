# Changelog

## 0.2.0

Added, alongside the 0.1.0 API (which is unchanged):

- JSON jobs: `createJob()` with an `Idempotency-Key`, `createBatch()`,
  `getJob()`, `listJobs()`, `downloadOutput()` with an output index, and
  `waitForJob()`.
- Designs: `compileDesign()` and `renderDesign()`.
- Direct uploads: `upload()` (a string or a stream, PUT in chunks, the key never
  sent to the upload URL), `getAsset()`, `deleteAsset()`.
- `operations()`, `usage()` with grouping, `deleteEndUser()`.
- `createEmbedToken()`.
- Storage destinations: list, create, get, update, delete, test, presign.
- Webhook endpoints: list, create, get, update, delete, rotate, deliveries, test.
- `SnappedCut::verifyWebhook()`, constant-time and five-minute fresh.
- `SnappedCutError::$errorCode`, `isOutOfCredits()` and `isRateLimited()`.
- User-Agent is `snappedcut-php/0.2.0`.

## 0.1.0 — first release

Cloud renders from a bundle (chunked and resumable), jobs, output, wait, and the
account.
