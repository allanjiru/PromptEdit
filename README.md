# PromptEdit

[![Deploy](https://github.com/allanjiru/PromptEdit/actions/workflows/deploy.yml/badge.svg)](https://github.com/allanjiru/PromptEdit/actions/workflows/deploy.yml)

PromptEdit is an authenticated image-generation application built for the
PromptEdit Senior Product Engineer take-home assessment. It integrates with the
asynchronous Pixelforge API, calculates token-based pricing with a 25% markup,
and maintains an exact, auditable credit ledger from reservation through final
settlement.

**Live application:** [http://5.222.0.13](http://5.222.0.13)

Register an account, use **Buy credits** to add simulated assessment credits,
and submit a generation. No card is required and no real payment is made.

## Product journey

1. Enter a prompt and choose the image size and quality. Up to four reference
   image URLs are optional.
2. Review the server-calculated estimated charge and available balance before
   submitting.
3. The estimated amount is reserved atomically and the request is submitted to
   Pixelforge by a queue worker.
4. Studio follows the generation through queued, processing, completed, or
   failed states.
5. A successful result is settled from its actual token usage. A failed result
   releases the full reservation and costs the user nothing.
6. History displays the estimate, final charge, status, prompt, settings, and
   privately served result image.

## Stack and assessment behavior

- PHP 8.3+ and Laravel 13
- Vue 3, TypeScript, Inertia.js, Tailwind CSS 4, and Vite Plus
- A vendor-supported PostgreSQL release (CI uses PostgreSQL 17), Laravel
  database queues, and Laravel's scheduler
- Pixelforge generation API and signed webhooks
- Nanodollar wallet accounting, a 25% markup, atomic reservations, and
  exactly-once settlement
- Duplicate/out-of-order webhook protection, bounded provider reconciliation,
  submission recovery, and private image persistence
- Simulated, authenticated reviewer credits; no real payment processing

## Correctness and resilience

Money is stored as integer nanodollars (`USD × 1,000,000,000`). Provider rates
are converted from per-million-token prices without using floating-point
arithmetic for settlement. The final customer charge is the provider cost plus
25%, rounded up to the smallest stored unit.

Each generation uses a client request UUID and an immutable provider payload.
Wallet reservations and ledger entries are written under PostgreSQL row locks.
Submission retries reuse the same Pixelforge idempotency key, while webhook
event IDs and financial transaction keys are unique in the database. As a
result, retries and duplicate deliveries cannot create duplicate generations or
charges.

Webhook events are signature-verified against the raw request body, persisted
before processing, and handled asynchronously. State transitions are monotonic,
so duplicate and out-of-order events cannot move a terminal generation
backwards. Scheduled recovery covers uncertain submissions, missed webhooks,
delayed results, and private-image persistence.

Useful Pixelforge test prompts include:

```text
Reviewer sample: abstract sunrise over geometric hills
[slow] Reviewer sample: luminous glass city at dusk
[duplicate] Reviewer sample: layered blue and gold waves
[fail] Reviewer sample: deliberate failure-path verification
```

## Local setup

Install PHP 8.3 or newer, Composer 2, Node.js 22, npm, and a vendor-supported
PostgreSQL release (CI uses PostgreSQL 17). Required
PHP extensions are BCMath, PDO, PDO PostgreSQL, Fileinfo, Filter, Hash, JSON,
Mbstring (or Composer's installed polyfill), OpenSSL, PCRE, Session, Tokenizer,
DOM, and LibXML. The application itself explicitly requires `ext-bcmath`,
`ext-pdo`, and `ext-pdo_pgsql`; other requirements are enforced by locked
framework dependencies.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
```

Create a dedicated local PostgreSQL database and account, then set
`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and
`DB_PASSWORD`. Run migrations only against the intended local database:

```bash
php artisan migrate
npm run build
```

Do not use SQLite for application or migration validation: several migrations,
locking paths, webhook inserts, and concurrency guarantees are PostgreSQL
specific. Tests use the isolated `promptedit_test` PostgreSQL database configured
in `phpunit.xml`; never point that configuration at development or production.

For local development, start the web server, queue listener, log viewer, and
frontend development server with:

```bash
composer dev
```

This convenience command is not a production process supervisor.

## Frontend

```bash
npm ci
npm run types:check
npm run check
npm run build
```

The production build emits hashed assets and `public/build/manifest.json`.
`resources/css/app.css` is the Tailwind 4 entry point and contains both light and
dark theme tokens. Production must not contain `public/hot` and must serve the
generated `public/build` directory from the same release as the PHP code.

## Queues and scheduler

Production uses the database queue and four named queues: `generations`,
`webhooks`, `images`, and `default`. Run supervised, long-lived workers for all
four; deploy migrations before starting workers for the new release. See
[docs/deployment.md](docs/deployment.md) for commands, timeout/retry alignment,
failed-job operations, and Supervisor/host-native process requirements.

Run Laravel's scheduler once each minute from exactly one host-level trigger:

```cron
* * * * * cd /var/www/promptedit/current && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled recovery covers delayed webhooks, uncertain submissions, missing
private images, and bounded result reconciliation. Shared database cache locks
prevent overlapping and duplicate multi-node scheduler execution.

The daily `pixelforge:reconcile-usage` task compares the previous UTC day's
stored token snapshots and provider costs with Pixelforge's per-key usage report.
Run it with `--start-date` and `--end-date` for an explicit range of up to 92
days.

## Pixelforge

Create a separate Pixelforge API key for each environment. Configure
`PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY`, and the signing secret supplied for
that key in `PIXELFORGE_WEBHOOK_SECRET`. Register this callback with Pixelforge:

```text
POST https://your-host.example/api/webhooks/pixelforge
```

The signature is mandatory and is checked against the raw body with a
five-minute timestamp tolerance. A tunnel such as Cloudflare Tunnel or ngrok is
required for local webhook testing. The scheduler remains a bounded fallback
for missed deliveries.

## Simulated reviewer credits

The feature is disabled by default. Only an intentional reviewer deployment
should use:

```dotenv
DEMO_CREDITS_ENABLED=true
DEMO_CREDITS_MAX_TOTAL_USD=100
DEMO_CREDITS_RATE_LIMIT_PER_MINUTE=10
```

Authenticated reviewers can select a server-defined package in Studio. The
lifetime cap is enforced under a wallet lock, requests are rate-limited, and a
client UUID makes retries idempotent. No real money is charged. See
[docs/demo-credits.md](docs/demo-credits.md).

## Tests and validation

```bash
composer validate --strict
composer check-platform-reqs
composer test
npm run test:frontend
npm run types:check
npm run check
npm run build
git diff --check
```

`composer test` runs Pint, PHPStan/Larastan, and the Laravel test suite.
`php artisan test` rebuilds an isolated PostgreSQL test schema. Pricing has a
focused unit suite covering every token category, markup and rounding behavior,
formatting, invalid catalogs, and overflow protection. Feature tests exercise
wallet concurrency, provider retry/idempotency, webhook ordering and duplicates,
result reconciliation, private images, and demo-credit limits.

The read-only `php artisan deployment:migration-preflight` command checks an
existing database before a deployment migration. GitHub Actions runs the full
static-analysis and test gate, creates production assets, deploys over SSH, runs
migrations, restarts workers, and checks the health endpoint.

## Architecture and limitations

Wallet changes are an append-only ledger plus locked balance/reservation rows.
Provider submission, webhook handling, result reconciliation, and image storage
are idempotent and keep durable database state across queue failures. Generated
images are stored outside the public web root and are returned only by an
owner-authorized controller.

For one host, a persistent private volume is sufficient. Separate or ephemeral
web/worker instances require the same private volume mounted at the identical
`PRIVATE_FILESYSTEM_ROOT`. An object-storage adapter is not currently installed;
horizontal deployment without shared POSIX storage needs an explicitly designed
storage change first.

The nanodollar migration intentionally refuses legacy non-null transaction
metadata and has no safe automatic rollback. Existing databases with that data
require reviewed reconciliation; a fresh PostgreSQL database is recommended for
the assessment. Deployment and rollback procedures are in
[docs/deployment.md](docs/deployment.md), and the assessment journey is in
[docs/reviewer-guide.md](docs/reviewer-guide.md).
