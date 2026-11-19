# Managed billing deployment and verification

Status: implementation exists, but no live Stripe credentials or successful payment verification are included in this source delivery. Do not advertise checkout as operational until the checklist below passes.

## Environment contract

Set these in your deployment secret manager, never in git or a public Compose file:

| Variable | Purpose |
| --- | --- |
| `MANAGED_HOSTING=true` | Enforce managed plan quotas and enable billing eligibility. Self-hosted core defaults to false and does not require Stripe. |
| `STRIPE_SECRET` | Server-side Stripe secret API key; test and live environments must not be mixed. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for this deployment's Stripe webhook destination. |
| `STRIPE_PRICE_DEVELOPER` | Recurring monthly Developer price ID. |
| `STRIPE_PRICE_PRO` | Recurring monthly Pro price ID. |
| `STRIPE_PRICE_TEAM` | Recurring monthly Team price ID. |

Plan amounts and included usage are declared in `config/billing.php`. Create corresponding recurring prices in Stripe; the application does not create products or reconcile their currency/amount. Verify those values manually before enabling sales. `Billing::enabled()` requires managed mode, both secrets and all three price IDs. It detects configuration presence, **not** provider validity. The preview Compose file does not pass Stripe variables through: use a private deployment configuration to inject them. On managed hosting declare secret names in the deployment manifest as well as storing their values in the secret manager. Never commit actual values.

## Provider setup

Configure the public HTTPS destination `/billing/stripe/webhook` (see `bootstrap/app.php`). Subscribe to `customer.subscription.created`, `customer.subscription.updated`, and `customer.subscription.deleted`. Enable Stripe's customer billing portal, including the intended cancellation and payment-method behavior. Checkout uses subscription mode and the authenticated account's customer; the portal is only available after a customer exists.

The webhook verifies timestamped HMAC signatures within five minutes, deduplicates event IDs, and retrieves authoritative subscription state from Stripe before updating entitlements. A browser success redirect does not grant a plan. Paid entitlement requires an active/trialing status with an unexpired paid-through timestamp; other states fall back to Free. Decide and disclose whether this no-grace-period policy is appropriate before launch.

## Required verification before accepting payments

1. In a separate test environment, configure test keys and matching recurring test prices. Confirm missing configuration returns unavailable states without claiming payment was taken.
2. Register a test account and complete authenticated checkout with Stripe's published test payment methods. Verify the actual price/currency and return URL.
3. Verify Stripe webhook delivery succeeds, then verify `/api/usage` reports the expected plan, quota and retention. Do not manually edit the billing database to simulate success.
4. Re-deliver the same event and verify idempotency; send an invalid signature and verify rejection. Inspect server/provider errors without recording secrets or webhook bodies.
5. Exercise upgrade, cancellation and expiry through the billing portal; verify Free fallback and quota enforcement. Check webhook retry/recovery after a transient provider failure.
6. Repeat an authorized live transaction/refund procedure with owner approval and the correct live-mode secrets before public launch. Payment actions requiring an owner remain an external authorization gate.

Automated mocked-provider tests live in `tests/Feature/BillingHistoryTest.php`; they do not establish live payment readiness. No tax, invoicing, refund-policy, legal or team-membership readiness is implied by these tests.
