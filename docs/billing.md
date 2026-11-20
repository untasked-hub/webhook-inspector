# Managed billing deployment and verification

Status: implementation exists, but no live Stripe credentials or successful payment verification are included in this source delivery. Do not advertise checkout as operational until the checklist below passes.

## Environment contract

Set these in your deployment secret manager, never in git or a public Compose file:

| Variable | Purpose |
| --- | --- |
| `MANAGED_HOSTING=true` | Enforce managed plan quotas and enable billing eligibility. Self-hosted core defaults to false and does not require Stripe. |
| `STRIPE_MODE` | Explicit `test` or `live`. Unset or invalid disables billing without disabling managed quotas. |
| `STRIPE_SECRET` | Server-side Stripe secret API key. Its mode prefix must match `STRIPE_MODE`. Preview Compose maps the Vault name `STRIPE_SECRET_KEY` to this application variable and fixes mode to `test`. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for this deployment's Stripe webhook destination. |
| `STRIPE_PRICE_DEVELOPER` | Recurring monthly Developer price ID. |
| `STRIPE_PRICE_PRO` | Recurring monthly Pro price ID. |
| `STRIPE_PRICE_TEAM` | Recurring monthly Team price ID. |

Plan amounts and included usage are declared in `config/billing.php`. Create corresponding recurring prices in Stripe; the application does not create products or reconcile their currency/amount. Verify those values manually before enabling sales. `php artisan billing:preflight` checks explicit mode, API key prefix, signing-secret format and three distinct price IDs without contacting Stripe or printing values. Run `docker compose exec web php artisan billing:preflight` in the isolated preview. Exit 0 checks local configuration only; it does not verify credentials, price ownership or mode, the webhook destination, or portal setup. Inject the credentials and prices through the preview secret manager. Never copy live values into preview or use a candidate deployment that shares production data. Do not switch modes in a database that has billing records: use separate deployments, databases and Stripe settings for test and live. The hosted release manifest deliberately does not inject Stripe variables yet.

## Provider setup

Configure the public HTTPS destination `/stripe/webhook` (see `bootstrap/app.php`). Subscribe to `customer.subscription.created`, `customer.subscription.updated`, and `customer.subscription.deleted`. Enable Stripe's customer billing portal, including the intended cancellation and payment-method behavior. Checkout uses subscription mode and the authenticated account's customer; the portal is only available after a customer exists.

Outbound requests pin Stripe API `2025-03-31.basil`, which supports Checkout on accounts with Managed Payments enabled. Stripe's webhook destination API version is separate; the app uses the subscription ID from the signed event and reads the authoritative billing period from the single recognized plan item returned by the pinned API. A missing item period, unknown price, or multiple items does not grant paid entitlement.

The webhook verifies timestamped HMAC signatures within five minutes, requires the event mode to match the deployment, deduplicates event IDs, and retrieves current subscription state from Stripe before updating entitlements. Provider errors and mismatched responses do not consume retryable events. A browser success redirect does not grant a plan. Paid entitlement requires an active/trialing status with an unexpired paid-through timestamp; other states fall back to Free. Decide and disclose whether this no-grace-period policy is appropriate before launch.

## Required verification before accepting payments

1. Keep paid checkout disabled in production. Use an authorized, isolated preview with its own disposable database and HTTPS origin, synthetic accounts only, and no production secrets or customer data. Inject only matching Stripe test-mode credentials, webhook signing secret, and recurring test prices via the secret manager. Confirm missing configuration returns unavailable states without claiming payment was taken. Configuration presence alone does not clear the release gate.
2. Register a test account and complete authenticated checkout with Stripe's published test payment methods. Verify the actual price/currency and return URL.
3. Verify Stripe webhook delivery succeeds, then verify `/api/usage` reports the expected plan, quota and retention. Do not manually edit the billing database to simulate success.
4. Re-deliver the same event and verify idempotency; send an invalid signature and verify rejection. Inspect server/provider errors without recording secrets or webhook bodies.
5. Exercise upgrade, cancellation and expiry through the billing portal; verify Free fallback and quota enforcement. Check webhook retry/recovery after a transient provider failure.
6. Repeat an authorized live transaction/refund procedure with owner approval and the correct live-mode secrets before public launch. Payment actions requiring an owner remain an external authorization gate.

## Operational checks

- Before creating a session, check each configured Stripe test price in the Stripe dashboard: correct test account, active recurring monthly price, expected currency and amount. Confirm the portal configuration is active in the same test account and the preview HTTPS webhook destination is reachable from Stripe. A local preflight pass cannot replace these checks.
- Monitor non-2xx Stripe event deliveries and sustained checkout/portal 503s. When investigating, record only timestamp, operation, response status and an internal correlation reference. Keep raw Stripe bodies, signed headers, API keys and Checkout/portal URLs out of logs and screenshots.
- For retries, fix the underlying signing, connectivity or provider error and use Stripe's authorised redelivery from the matching environment. Compare usage entitlement, then verify duplicate delivery has no second effect. Do not edit production billing records to simulate success.
- Before any live activation, rehearse database backup and restore with the application key in an isolated environment; monitor retention jobs and confirm backup retention separately. Disabling the billing integration stops session creation and webhook reconciliation; it does not cancel Stripe subscriptions. If live billing is already in use, reconcile missed events before re-opening sales and communicate any impact without claiming customer subscriptions were cancelled.

Automated mocked-provider tests live in `tests/Feature/BillingHistoryTest.php`; they do not establish live payment readiness. No tax, invoicing, refund-policy, legal or team-membership readiness is implied by these tests.
