# Managed billing deployment and verification

Status: **Live managed billing on the hosted deployment.** Paid plans can be purchased through Stripe Checkout in live mode. Self-hosted deployments default to billing disabled and do not require Stripe.

## Hosted deployment vs. self-hosted environment contract

Set billing variables only in your deployment secret manager, never in git or a public Compose file.

### Hosted deployment (Untasked managed deployment)

The hosted deployment uses the dedicated live Stripe credentials declared for it. Live charging activates only when `STRIPE_MODE=live` and a live secret key are present; anything else fails closed (checkout disabled).

| Variable | Purpose |
| --- | --- |
| `STRIPE_MODE` | Set to `live` to activate live Stripe billing. Any other value disables charging. |
| `STRIPE_SECRET_KEY` | Live Stripe secret API key (`sk_live_...` or `rk_live_...`). Declared in `untasked.yaml` secrets. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret (`whsec_...`) for the hosted deployment's Stripe webhook destination. |
| `STRIPE_PRICE_DEVELOPER` | Recurring monthly live Developer price ID (`price_...`). |
| `STRIPE_PRICE_PRO` | Recurring monthly live Pro price ID (`price_...`). |
| `STRIPE_PRICE_TEAM` | Recurring monthly live Team price ID (`price_...`). |
| `BILLING_CHECKOUT_ENABLED` | Optional kill switch. Set `false` to disable checkout, portal, and webhook endpoints (`503`) without disabling managed quotas. |
| `BILLING_ENABLED` | Optional override (`false` disables managed quotas/billing; self-hosted deployments default to `false`). |

### Self-hosted / standalone operator deployment

Self-hosted installations run with billing disabled by default (`MANAGED_HOSTING=false`) and include all core webhook capture, inspection, replay, and forwarding features without Stripe. Operators running their own managed instance use the standalone variable set:

| Variable | Purpose |
| --- | --- |
| `MANAGED_HOSTING=true` | Enforce managed plan quotas and enable billing eligibility. |
| `BILLING_CHECKOUT_ENABLED=true` | Explicitly enable checkout, portal, and webhook endpoints. |
| `STRIPE_MODE` | Explicit `test` or `live`. Unset or invalid disables billing without disabling managed quotas. |
| `STRIPE_SECRET` | Server-side Stripe secret API key. Its mode prefix must match `STRIPE_MODE`. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for this deployment's Stripe webhook destination. |
| `STRIPE_PRICE_DEVELOPER` | Recurring monthly Developer price ID. |
| `STRIPE_PRICE_PRO` | Recurring monthly Pro price ID. |
| `STRIPE_PRICE_TEAM` | Recurring monthly Team price ID. |

Plan amounts and included usage are declared in `config/billing.php`. Create corresponding recurring prices in Stripe; the application does not create products or reconcile their currency/amount. `php artisan billing:preflight` checks explicit mode, API key prefix, signing-secret format and three distinct price IDs without contacting Stripe or printing values. Inject the credentials and prices through the secret manager. Do not switch modes in a database that has billing records: use separate deployments, databases and Stripe settings for test and live.

## Provider setup

Configure the public HTTPS destination `/stripe/webhook` (or alias `/billing/stripe/webhook`; see `bootstrap/app.php`). Subscribe to `customer.subscription.created`, `customer.subscription.updated`, and `customer.subscription.deleted`. Enable Stripe's customer billing portal, including the intended cancellation and payment-method behavior. Checkout uses subscription mode and the authenticated account's customer; the portal is only available after a customer exists.

Outbound requests pin Stripe API `2025-03-31.basil`, which supports Checkout on accounts with Managed Payments enabled. Stripe's webhook destination API version is separate; the app uses the subscription ID from the signed event and reads the authoritative billing period from the single recognized plan item returned by the pinned API. A missing item period, unknown price, or multiple items does not grant paid entitlement.

The webhook verifies timestamped HMAC signatures within five minutes, requires the event mode to match the deployment, deduplicates event IDs, and retrieves current subscription state from Stripe before updating entitlements. Provider errors and mismatched responses do not consume retryable events. A browser success redirect does not grant a plan. Paid entitlement requires an active/trialing status with an unexpired paid-through timestamp; other states fall back to Free.

## Operational checks

- Before creating a session, check each configured Stripe price in the Stripe dashboard: correct account, active recurring monthly price, expected currency and amount. Confirm the portal configuration is active in the same account and the HTTPS webhook destination is reachable from Stripe. A local preflight pass cannot replace these checks.
- Monitor non-2xx Stripe event de[REDACTED:STRIPE_MODE]ries and sustained checkout/portal 503s. When investigating, record only timestamp, operation, response status and an internal correlation reference. Keep raw Stripe bodies, signed headers, API keys and Checkout/portal URLs out of logs and screenshots.
- For retries, fix the underlying signing, connectivity or provider error and use Stripe's authorised rede[REDACTED:STRIPE_MODE]ry from the matching environment. Compare usage entitlement, then verify duplicate de[REDACTED:STRIPE_MODE]ry has no second effect. Do not edit production billing records to simulate success.
- Rehearse database backup and restore with the application key in an isolated environment before any mode change; monitor retention jobs and confirm backup retention separately. Disabling the billing integration stops session creation and webhook reconciliation; it does not cancel Stripe subscriptions.
