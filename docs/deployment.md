# PromptEdit production deployment

This runbook prepares a release; it does not imply that PromptEdit is deployed.
Substitute host-specific paths and process definitions only after choosing the
platform.

## 1. Prerequisites and host requirements

- Linux host/container with PHP 8.3+, PHP-FPM, Composer 2, Node.js 22/npm for
  builds, and a web server that terminates or receives HTTPS.
- PHP extensions: BCMath, PDO, PDO PostgreSQL, Fileinfo, Filter, Hash, JSON,
  OpenSSL, PCRE, Session, Tokenizer, DOM, and LibXML. Native Mbstring is
  recommended; the lock file also installs its polyfill.
- A vendor-supported PostgreSQL release reachable over a private network/TLS as
  appropriate (CI validates PostgreSQL 17). SQLite and MySQL are not supported
  deployment databases.
- A durable writable volume for `storage`, especially the generated-image root,
  and writable `bootstrap/cache`.
- Four supervised database-queue worker groups and one reliable minute-based
  scheduler trigger.
- A public HTTPS hostname. Localhost cannot receive Pixelforge callbacks.

Build dependencies may run in CI or a build image. A runtime image still needs
the built `public/build` assets and Composer production dependencies.

## 2. Production environment reference

Use the platform's encrypted secrets/configuration facility. Do not commit the
production environment file.

```dotenv
APP_NAME="PromptEdit"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_A_UNIQUE_KEY_WITH_PHP_ARTISAN_KEY_GENERATE
APP_URL=https://promptedit.example.com

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres.internal
DB_PORT=5432
DB_DATABASE=promptedit
DB_USERNAME=promptedit_app
DB_PASSWORD=REPLACE_WITH_SECRET
DB_SSLMODE=require

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
QUEUE_AFTER_COMMIT=true
QUEUE_FAILED_DRIVER=database-uuids
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

FILESYSTEM_DISK=local
PRIVATE_FILESYSTEM_ROOT=/var/lib/promptedit/private

PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=REPLACE_WITH_SECRET
PIXELFORGE_WEBHOOK_SECRET=REPLACE_WITH_INDEPENDENT_HIGH_ENTROPY_SECRET

DEMO_CREDITS_ENABLED=true
DEMO_CREDITS_MAX_TOTAL_USD=100
DEMO_CREDITS_RATE_LIMIT_PER_MINUTE=10

# Exact comma-separated proxy IPs or CIDRs; leave empty without a proxy.
TRUSTED_PROXIES=10.0.0.0/8
```

Generate `APP_KEY` once, store it durably, and reuse it across all web/worker
instances and releases. Never rotate it casually: sessions and encrypted values
depend on it. Use a different Pixelforge signing secret from the API key.

Set `TRUSTED_PROXIES` only to the actual load balancer/reverse proxy addresses.
Using `*` is safe only when the application cannot be reached except through a
trusted proxy. Incorrect proxy trust can let a client spoof scheme/client data;
missing proxy trust can generate HTTP URLs and non-secure cookie behavior behind
TLS termination. `APP_URL` must be the canonical public HTTPS origin.

The database cache is deliberately used for cross-process scheduler and unique
job locks. Do not change it to a per-instance file/array cache on a multi-process
or multi-host deployment. Use a distinct `CACHE_PREFIX` when a database/cache is
shared by multiple application installations.

## 3. PostgreSQL provisioning and pooling

Create a dedicated database and least-privileged application role with rights to
connect, use the application schema, create/alter schema objects during the
migration phase, and read/write application tables. Prefer separate migration
credentials if the host supports them. Do not embed credentials in an image.

Use `DB_SSLMODE=require` when the host mandates encrypted connections;
`verify-full` is preferable when the CA and hostname validation are correctly
provisioned. Follow the provider's exact TLS instructions.

With PgBouncer or another pooler, use transaction pooling only after verifying
Laravel and the provider's prepared-statement recommendations. Migrations and
session-level operations may need a direct PostgreSQL connection. Size PHP-FPM
and queue concurrency below the available database connection limit; every
worker can hold a connection.

Verify connectivity without migrating:

```bash
php artisan about --only=environment
php artisan db:show --counts
php artisan deployment:migration-preflight
```

`db:show --counts` may be expensive on a very large database; omit counts there.

## 4. Migration preflight and safety

First run the release against a fresh, isolated PostgreSQL database in CI or a
staging copy. The complete migration order succeeds on an empty database and
creates users, cache/session/queue tables, wallet and generation tables,
financial constraints, nanodollar values, webhook uniqueness/recovery state,
submission recovery state, and bounded result-reconciliation state.

For any existing database:

1. Take a verified PostgreSQL backup/snapshot and test its restore.
2. Point the release at a non-production restore and run
   `php artisan deployment:migration-preflight`.
3. Review `php artisan migrate:status`; do not use `migrate:fresh`,
   `migrate:refresh`, or `db:wipe`.
4. If preflight reports legacy transaction metadata, stop. Export only the
   affected transaction IDs and metadata for review. Identify which metadata
   keys represent monetary microdollars, define their exact nanodollar mapping,
   and implement/test a separate idempotent reconciliation migration. Preserve
   original values in an audit record. Do not null, delete, or generically scale
   unknown JSON.
5. If preflight reports BIGINT overflow risk, stop and reconcile those exact
   records with the financial owner. Never clamp a ledger value.

The conversion migration checks metadata before touching financial columns and
PostgreSQL rejects numeric overflow. Its rollback is intentionally disabled
because nanodollar values need not divide exactly by 1,000. A fresh PostgreSQL
database is therefore the safest assessment deployment.

## 5. Release and migration sequence

Use this order for a production release:

1. Build and test an immutable release without production secrets.
2. Provision the environment, PostgreSQL, HTTPS, and persistent private volume.
3. Back up an existing database and run the preflight against a restored copy.
4. Put the old application into the host's controlled maintenance/drain mode if
   required and stop dispatching new work. Do not terminate an active job
   mid-transaction.
5. Install production PHP dependencies with
   `composer install --no-dev --prefer-dist --optimize-autoloader` and install
   locked frontend dependencies with `npm ci` before `npm run build`.
6. Remove any development `public/hot` file. Verify
   `public/build/manifest.json` and hashed CSS/JS files exist.
7. Run `php artisan deployment:migration-preflight`, then
   `php artisan migrate --force` exactly once.
8. Only after migrations succeed, switch web traffic and start/restart queue
   workers on the new release. Run `php artisan queue:restart` only through the
   deployment process after schema compatibility is established.
9. Cache stable configuration/routes/events with `php artisan optimize`. Clear
   and rebuild caches whenever environment values change.
10. Enable the minute scheduler trigger, complete the verification checklist,
    then remove maintenance mode.

Never let updated workers process jobs against the old schema.

## 6. Frontend build and HTTPS asset behavior

Run `npm ci && npm run build`. Confirm:

```bash
test -s resources/css/app.css
test -s public/build/manifest.json
php artisan about
```

The manifest must reference existing hashed `.js` and `.css` assets and compiled
CSS must be non-empty. Serve `public/` as the only document root. HTTPS asset URLs
come from the request/`APP_URL`; configure the real proxy addresses so Laravel
trusts forwarded HTTPS. A production release never depends on the Vite dev
server or `public/hot`.

## 7. Persistent private storage

Generated PNGs use the `local` disk rooted at `PRIVATE_FILESYSTEM_ROOT` (default
`storage/app/private`). The disk's generic serving route is disabled. Only the
authenticated generation-image controller accepts a path matching the current
user and generation and returns a private, `nosniff` response.

- Single host: mount a persistent volume such as
  `/var/lib/promptedit/private`; make it writable by PHP-FPM and queue worker
  users and readable by the web user. Recommended modes are owner/group access
  (directories `0770`, files `0660`) with a shared service group and restrictive
  umask.
- Separate containers/hosts: mount the same durable private volume at the same
  root in every web process and every `images` worker. Database rows are not
  sufficient if the web instance cannot see the file.
- Ephemeral/replaced containers: never store the root only in a container layer.
  Use the host's persistent shared filesystem or implement a reviewed private
  object-storage adapter and streamed controller first. The repository does not
  currently install an S3 Flysystem adapter, so setting `FILESYSTEM_DISK=s3`
  alone does not move generated images.

Do not run `storage:link` for generated images and do not place the private root
under `public/`. Persistence is idempotent: an existing valid PNG is reused and
database URLs are replaced under a row lock.

## 8. Queue supervision

Queues and maximum job-level settings are:

| Queue         | Work                                          |    Job timeout | Attempts/backoff                                                                        |
| ------------- | --------------------------------------------- | -------------: | --------------------------------------------------------------------------------------- |
| `generations` | Provider submission and bounded result checks |            45s | submission 6; reconciliation 20 queue attempts but at most 12 durable provider requests |
| `webhooks`    | Signed event processing/retrieval             |            45s | 8; 10s through 15m backoff                                                              |
| `images`      | Download/validate/store PNGs                  |            90s | 5; 15s through 10m backoff                                                              |
| `default`     | Miscellaneous/default jobs                    | worker default | 3 worker attempts                                                                       |

`DB_QUEUE_RETRY_AFTER=180` is longer than the longest 90-second job plus cleanup
margin. Keep worker `--timeout` below 180; job-specific timeouts remain stricter.
Example process commands:

```bash
php artisan queue:work database --queue=generations --sleep=1 --tries=3 --timeout=120 --memory=256 --max-time=3600
php artisan queue:work database --queue=webhooks --sleep=1 --tries=3 --timeout=120 --memory=256 --max-time=3600
php artisan queue:work database --queue=images --sleep=1 --tries=3 --timeout=120 --memory=256 --max-time=3600
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=120 --memory=256 --max-time=3600
```

Start with one process per queue on a small assessment host. Increase
`generations`/`webhooks` to two only after observing provider rate limits and
database connections; image workers also consume bandwidth, memory, and shared
storage I/O. Define each command in Supervisor, systemd, or the host's native
worker facility with automatic restart, captured stdout/stderr, and graceful
termination longer than 120 seconds. A shell terminal is not supervision.

After each release, gracefully restart workers so they load new code. Inspect
failures with `php artisan queue:failed`; use `queue:retry <uuid>` only after the
cause is fixed and idempotency is understood. Do not retry settlement failures
by manually editing wallet rows.

## 9. Scheduler and recovery

Invoke `php artisan schedule:run` every minute through one reliable cron/native
scheduled task. `php artisan schedule:work` may be used only when the platform
supervises it as a long-running process. `php artisan schedule:list` shows:

- `pixelforge:recover-webhooks` every minute: requeues stale events with durable
  exponential retry state.
- `pixelforge:recover-submissions` every minute: leases uncertain submissions
  and preserves provider idempotency keys/wallet reservations.
- `generations:recover-images --limit=50` every minute: redispatches incomplete
  private persistence idempotently.
- `pixelforge:reconcile-results --limit=10` every minute: resumes due provider
  checks while preserving the established maximum of 12 provider requests.

Every task uses `onOneServer()` and `withoutOverlapping(5)`. These locks require
the shared atomic database cache and synchronized clocks. Queue jobs and fallback
commands use the same durable database attempt, due-time, claim, and exhaustion
fields, so restarts do not reset provider budgets. Do not increase cadence or
limits without checking Pixelforge quotas.

## 10. Pixelforge webhook configuration

1. Set the canonical public HTTPS `APP_URL`.
2. Set the API key and a distinct signing secret in the platform secret store.
3. Register `POST https://promptedit.example.com/api/webhooks/pixelforge` in the
   Pixelforge dashboard. Localhost and `127.0.0.1` are not remotely reachable.
4. Run the local signed-webhook tests before release:
   `php artisan test --filter=PixelforgeWebhookTest`.
5. Use Pixelforge's signed dashboard ping, if available, to verify a 2xx response.
   Missing, stale, malformed, or incorrect signatures must receive 403/validation
   failure. Do not weaken verification for a health check.
6. For a reviewer-generated image, confirm the non-ping event is persisted in
   `provider_webhook_events`, then processed by the `webhooks` worker. Confirm the
   generation becomes terminal, the reservation is settled/released exactly
   once, and duplicate delivery does not add a second ledger settlement.
7. Confirm the scheduled bounded reconciliation still completes a generation if
   a webhook is delayed or missed.

Do not log raw signing headers, authorization headers, API keys, or full webhook
payloads outside the protected database event record.

## 11. Reviewer demo credits

Enable only for the assessment deployment:

```dotenv
DEMO_CREDITS_ENABLED=true
DEMO_CREDITS_MAX_TOTAL_USD=100
DEMO_CREDITS_RATE_LIMIT_PER_MINUTE=10
```

The route is inside `auth` middleware and a named per-user/IP limiter. Package
IDs and amounts come from server configuration, browser-supplied amounts and
identity fields are rejected, the total cap is locked and enforced atomically,
and each UUID is an idempotent ledger key. This lets a reviewer fund an empty
wallet without administrator access. Disable it for any non-review public use.

## 12. Production verification

Before opening traffic:

1. `composer validate --strict` and `composer check-platform-reqs` pass.
2. `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, secure cookies, and
   exact trusted proxies are visible in `php artisan about`/`config:show` without
   printing secrets.
3. `php artisan migrate:status` shows every migration applied and
   `deployment:migration-preflight` succeeds.
4. `php artisan route:list` shows generation, history, image, retry, and demo
   credit routes behind `auth`; the API webhook remains public but signed.
5. `php artisan schedule:list` shows all four recovery tasks; supervised workers
   exist for all four queues.
6. `/up` returns 200 through public HTTPS, registration/login work, and cookies
   are Secure/HttpOnly/SameSite=Lax.
7. Static build files load with no Vite hot-server request and both themes render.
8. Follow [reviewer-guide.md](reviewer-guide.md) with a new account. Confirm
   private image URLs cannot be opened from a different account.

## 13. Operational diagnostics and troubleshooting

Use protected operator access and avoid pasting output into public tickets:

```bash
php artisan about
php artisan schedule:list
php artisan queue:failed
php artisan migrate:status
php artisan config:show queue
php artisan config:show filesystems
```

Database queue backlog can be measured with read-only SQL:

```sql
SELECT queue, count(*) AS ready_or_reserved
FROM jobs
GROUP BY queue
ORDER BY queue;

SELECT queue, count(*) AS failures, max(failed_at) AS latest
FROM failed_jobs
GROUP BY queue
ORDER BY queue;

SELECT status, count(*)
FROM provider_webhook_events
GROUP BY status;

SELECT count(*) AS exhausted
FROM generations
WHERE result_reconciliation_exhausted_at IS NOT NULL
  AND status IN ('queued', 'processing');
```

- Submission failures: inspect `failed_jobs`, generation submission status/error,
  and application logs; never print the API key or provider Authorization header.
- Webhook failures: inspect event status and the bounded `processing_error`, then
  confirm the worker and signing secret. Do not expose raw payloads publicly.
- Image failures: confirm the `images` worker can resolve provider HTTPS, write
  the private root, and that web processes mount the same root. Errors deliberately
  avoid recording provider temporary URLs/private paths.
- Scheduler failures: verify cron execution, shared cache tables, system clock,
  and scheduler logs. Run `schedule:list`; do not start a second unmanaged loop.
- Settlement errors: stop manual retries, preserve wallet/generation/transaction
  rows, and inspect the exception plus idempotency key. Never repair balances with
  ad-hoc SQL.

Configure log rotation and retention at the host. Application logs should be
readable only by operators. The code reports exceptions and records failed jobs
without deliberately logging API secrets, cookies, authorization headers, or
signing headers; review platform HTTP access-log header capture separately.

## 14. Safe rollback

Prefer forward fixes. Before migration, retain the prior immutable release and a
tested PostgreSQL backup. If the new release fails before migrations, switch back
without schema action. If migrations ran, first verify the old code is compatible
with the new additive columns; then drain new workers, switch web/workers together,
and monitor queues.

Do not run `migrate:rollback` blindly. The nanodollar conversion intentionally
throws on `down()` because division could lose financial precision. Restoring the
pre-deployment database snapshot is the only general rollback for that migration,
and it also discards post-snapshot writes. If traffic produced financial writes,
stop and design an audited forward reconciliation or controlled restore/merge;
never splice ledger rows ad hoc. A failed migration must not be followed by new
workers or traffic.
