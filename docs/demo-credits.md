# Simulated demo credits

PromptEdit can expose an assessment-only **Buy Credits** workflow. It does not
contact a payment provider and no real money is charged.

Set `DEMO_CREDITS_ENABLED=true` in an intentional reviewer/demo deployment.
The feature is disabled by default. Reviewers can then register, open Studio,
choose Starter ($5), Standard ($10), or Plus ($20), confirm the simulated
purchase, and generate without using Tinker, SQL, or Artisan.

Each request carries a browser-generated UUID. The server scopes it to the
authenticated user and stores it in the unique wallet ledger key. Retrying the
same request returns the same transaction; changing its package is rejected.
Amounts and package definitions come only from `config/demo_credits.php`.
Browser-supplied amounts, wallet IDs, and user IDs are prohibited.

The endpoint is authenticated and rate-limited to 10 requests per minute by
default. Wallet row locking serializes grants and enforces a cumulative $100
demo-credit cap atomically. Configure those safeguards with:

```dotenv
DEMO_CREDITS_ENABLED=true
DEMO_CREDITS_MAX_TOTAL_USD=100
DEMO_CREDITS_RATE_LIMIT_PER_MINUTE=10
```

Only whole-dollar positive caps are supported. Demo purchases add available
funds, never reserved funds. Generation reservations, failed-generation
releases, and actual provider-cost-plus-25% settlement remain separate.
