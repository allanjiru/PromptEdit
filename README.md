# PromptEdit

PromptEdit is an authenticated AI image-generation application built for a
Laravel, Vue, and Inertia technical assessment. Users can estimate a request,
reserve wallet funds, submit generation work to Pixelforge, follow live status,
and view privately persisted results and final costs in History.

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

For local development, `composer dev` starts the development processes. That
convenience command is not a production supervisor.

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

## Pixelforge

Configure `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY`, and a high-entropy
`PIXELFORGE_WEBHOOK_SECRET`. Register this public HTTPS callback with Pixelforge:

```text
POST https://your-host.example/api/webhooks/pixelforge
```

The signature is mandatory and is checked against the raw body with a five-minute
timestamp tolerance. `localhost` and `127.0.0.1` cannot receive remote provider
webhooks. The scheduler remains a bounded fallback for missed deliveries.

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
php artisan test
npm run test:frontend
npm run types:check
npm run check
npm run build
vendor/bin/pint --test
vendor/bin/phpstan analyse
git diff --check
```

`php artisan test` rebuilds an isolated PostgreSQL test schema. The read-only
`php artisan deployment:migration-preflight` command is for checking an existing
database before a deployment migration.

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
