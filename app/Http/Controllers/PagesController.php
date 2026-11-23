<?php

namespace App\Http\Controllers;

use App\Support\Billing;
use Inertia\Inertia;
use Inertia\Response;

class PagesController extends Controller
{
    private function pages(): array
    {
        return [
            'pricing' => [
                'title' => 'Pricing',
                'description' => 'Free to self-host forever. Managed hosting adds higher limits and longer retention.',
                'sections' => [[
                    'h' => 'Managed plans and Hosted Sandbox status',
                    'p' => [
                        'Request quotas are enforced per account each calendar month. Live paid billing is not yet open: the hosted Sandbox runs in Stripe Test Mode only (use Stripe test cards; no real charges are taken).',
                        'Self-hosted installations run with billing disabled by default (MANAGED_HOSTING=false) and include all core features without Stripe. See the Self-hosting guide for standalone deployment.',
                    ],
                ]],
                'plans' => collect(config('billing.plans'))->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name'], 'price' => $p['price'], 'requests' => $p['requests'], 'retention' => $p['retention']])->values()->all(),
                'billing_enabled' => Billing::enabled(),
                'billing_mode' => Billing::enabled() ? config('billing.stripe_mode') : null,
                'signed_in' => auth()->check(),
                'selected_plan' => in_array(request()->query('plan'), ['developer', 'pro', 'team'], true) ? request()->query('plan') : null,
                'billing_note' => !Billing::enabled()
                    ? 'Paid checkout is currently unavailable on this deployment: billing is not configured.'
                    : (config('billing.stripe_mode') === 'test'
                        ? 'Hosted Sandbox runs in Stripe Test Mode. Use Stripe test card details only; no real payment will be taken and live paid plans are not yet open.'
                        : 'Checkout is configured on this deployment.'),
                'policies' => [
                    ['h' => 'What counts toward my quota?', 'p' => 'Accepted inbound captures count toward the project owner’s quota across all endpoints. Usage starts a new calendar month in UTC. Deleting history does not reset usage.'],
                    ['h' => 'What happens at the limit?', 'p' => 'Managed receivers reject new captures once the account reaches its monthly limit. Rejected requests are not stored or forwarded. There are no automatic overage charges or quota grace periods.'],
                    ['h' => 'What happens after cancellation?', 'p' => 'Where paid billing is enabled, the billing portal shows the cancellation options available for your subscription. Paid limits continue only while the subscription is active or trialing and its paid-through time has not passed. A cancellation scheduled for period end can retain paid limits until then. An ineligible or expired subscription falls back to Free with no additional grace period; the Free limits then apply, including to retention.'],
                    ['h' => 'Are refunds available?', 'p' => 'No refund policy has been finalized for paid launch. This page does not promise refunds or prorated credits.'],
                ],
            ],
            'docs' => [
                'title' => 'Documentation',
                'description' => 'Capture, inspect, forward and replay webhooks.',
                'sections' => [
                    ['h' => 'Quick start', 'p' => ['Register, create a project and endpoint, copy its private receiver URL, then send a test request. Set RECEIVER_URL privately in your shell; never publish it. Select the captured request in the dashboard to inspect it.'], 'code' => "# Set RECEIVER_URL privately; never publish it.\ncurl -X POST \"\$RECEIVER_URL\" \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"hello\":\"world\"}'"],
                    ['h' => 'Agent & CLI quickstart', 'p' => ['Hand an AI agent, CI job or script everything it needs in one step. Creating projects, endpoints and API tokens requires an authenticated dashboard session; /api/v1 handles inspection, polling and replay. In the dashboard, select an endpoint (its Endpoint ID is shown on the Receive URL card), open Project API tokens (Project ID shown in the header), generate a token and press Copy agent env. Copy the receiver URL separately with Copy URL: anyone with it can send requests.', 'A read token can list endpoints, poll requests and fetch details; replay (POST /api/v1/requests/{id}/replay with confirm: true) requires read+replay scope. Requests are returned newest first; pass ?before=<next_before> for older pages. Bodies are returned as payload.body_base64 so binary payloads survive; decode with base64 -d. Endpoint listings never include receiver URLs or signing secrets. Store tokens in a secret manager, never in source control, URLs or shared logs.'], 'code' => "# Copy these from the dashboard (Copy agent env + Copy URL); keep them private.\nexport WI_BASE_URL=\"https://your-deployment.example\"\nexport WI_ENDPOINT_ID=\"<endpoint id>\"\nexport WI_API_TOKEN=\"<wi_... token>\"\nexport WI_RECEIVER_URL=\"<receiver URL>\"\n\n# 1. Send a test webhook (no API token needed for capture)\ncurl -sS -X POST \"\$WI_RECEIVER_URL\" -H \"Content-Type: application/json\" -d '{\"hello\":\"agent\"}'\n\n# 2. Poll the newest captured requests\ncurl -sS -H \"Authorization: Bearer \$WI_API_TOKEN\" \\\n  \"\$WI_BASE_URL/api/v1/endpoints/\$WI_ENDPOINT_ID/requests\"\n\n# 3. Fetch one request and decode its body (payload.body_base64)\nREQUEST_ID=\$(curl -sS -H \"Authorization: Bearer \$WI_API_TOKEN\" \\\n  \"\$WI_BASE_URL/api/v1/endpoints/\$WI_ENDPOINT_ID/requests\" | jq -r '.requests[0].id')\ncurl -sS -H \"Authorization: Bearer \$WI_API_TOKEN\" \"\$WI_BASE_URL/api/v1/requests/\$REQUEST_ID\" \\\n  | jq -r '.payload.body_base64' | base64 -d"],
                    ['h' => 'Inspector', 'p' => ['Inspect methods, timestamps, headers, query parameters and bodies. JSON supports highlighting, search and copy. Auto-refresh checks every two seconds while visible and enabled, merging new requests into loaded history without discarding older pages. Pause it at any time; failed refreshes preserve the inspector and can be retried. Payloads can contain secrets.']],
                    ['h' => 'Forwarding and replay', 'p' => ['Destinations must be public http(s) URLs on ports 80/443/8080/8443. Hostnames are resolved, every IPv4 answer is validated and the validated IP is pinned for the connection. Redirects are never followed; private, loopback, link-local and metadata addresses are refused. Delivery uses public IPv4 A records only, including for dual-stack hostnames; IPv6-only destinations are refused (fail closed).', 'Replay can cause external side effects and requires explicit confirmation. Query-bearing destination URLs are rejected. Credential-like headers and query parameters are omitted by default. Destination URLs are encrypted at rest.']],
                    ['h' => 'Conditional forwarding and destination health', 'p' => ['Open Endpoint settings and set any combination of HTTP method, exact header value and JSON dot-path with an exact string value. All configured conditions must match. For example: POST, X-Event: orders, and data.type = paid. Header names are case-insensitive; values are case-sensitive. Missing paths and non-string values do not match. Do not use credentials in rules. Non-matching requests are still captured and can be replayed explicitly.', 'Leave the URL blank to retain a configured destination. Clear all conditions to forward unconditionally. The health badge reflects retained forwarding outcomes, not a live reachability probe. Delivery issues include blocked or retrying deliveries; a temporary pause means the circuit breaker is preventing new forwards. A changed destination starts with no observed health.']],
                    ['h' => 'Custom receiver responses', 'p' => ['Open Receiver response & signature verification in the dashboard to configure an HTTP status, application/json or text/plain Content-Type, and a response body up to 16 KiB. A 204 response has no body. Custom errors still capture requests and may cause the sender to retry. Responses cannot set redirect locations or arbitrary headers.']],
                    ['h' => 'Signature verification', 'p' => ['Optionally configure an encrypted endpoint signing secret. Auto mode detects Stripe-Signature, X-Hub-Signature-256 (GitHub), X-Slack-Signature with X-Slack-Request-Timestamp, or X-Webhook-Signature (hex HMAC-SHA256). Stripe and Slack timestamps must be within five minutes. The inspector shows verified, invalid, missing, or not configured. Verification is diagnostic: invalid requests are still captured. Secrets and signature headers are not exposed in history or exports.']],
                    ['h' => 'Project collaboration', 'p' => ['Owners can create private, single-use invitation links from Manage project team. Sign in first, then open the invitation and explicitly accept. Links expire in 24 hours; share only with trusted teammates. Members can configure endpoints and replay requests. Viewers can inspect and export retained captures but cannot change data. Owners alone rename/delete projects, invite, change roles and revoke access. Membership numbers identify access entries without exposing account details; record who received each invitation privately. Analytics and billing remain scoped to the owning account; captured usage is billed to the project owner.']],
                    ['h' => 'Project REST API: authentication', 'p' => ['Open Project API tokens in the dashboard. Owners and members can generate tokens with read or read+replay scope; members manage only their own tokens, owners can revoke any project token. Viewers cannot mint tokens. Tokens are shown once, SHA-256 hashed at rest, and checked against current project membership on every call. Removal immediately prevents further access; downgrading to viewer prevents replay. Revoke unused credentials. Never put API tokens in URLs, source control or shared logs.', 'Set BASE_URL to this deployment origin and API_TOKEN privately in your shell. All responses are JSON with Cache-Control: no-store. Invalid/revoked tokens return 401, insufficient scope 403, inaccessible or expired resources 404, validation 422, and rate limits 429. Limits: 120 calls/minute shared per project and 180/minute per source IP. Receiver URLs and signing secrets are never listed by this API.'], 'code' => 'curl --fail-with-body -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/projects/$PROJECT_ID/endpoints"'],
                    ['h' => 'REST API: poll request history', 'p' => ['GET /api/v1/endpoints/{endpoint}/requests returns requests metadata and next_before. Pages contain up to 100 retained requests, newest first; pass before=next_before for older pages. Poll the first page and deduplicate by id for new arrivals. Optional filters: method, from, to, content_type, min_size, max_size, status (none/queued/retrying/succeeded/failed/blocked), and q (case-insensitive body/header/query search across the newest 1,000 prefiltered captures). This API always returns JSON; use dashboard exports for CSV/NDJSON/cURL.'], 'code' => 'curl --fail-with-body --get -H "Authorization: Bearer $API_TOKEN" --data-urlencode "method=POST" --data-urlencode "before=$NEXT_BEFORE" "$BASE_URL/api/v1/endpoints/$ENDPOINT_ID/requests"'],
                    ['h' => 'REST API: fetch payload', 'p' => ['GET /api/v1/requests/{request} returns request metadata and payload with headers, query, signature diagnostics and body_base64. Decode body_base64 for the exact captured bytes. Sensitive credential headers/query values are redacted on ingestion, but bodies may still contain secrets.'], 'code' => 'curl --fail-with-body -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/requests/$REQUEST_ID"'],
                    ['h' => 'REST API: replay', 'p' => ['POST /api/v1/requests/{request}/replay requires read+replay scope, a public destination URL and confirm=true acknowledging external side effects. A 202 response contains the queued delivery id, not a guarantee of successful delivery. Inspect delivery history in the dashboard. SafeHttp applies identical DNS validation, connection pinning and SSRF protections at enqueue and delivery time. No redirects, credentials, query strings or fragments in configured destinations. Do not automatically retry replay calls after ambiguous network failures: each call creates a new delivery.'], 'code' => 'curl --fail-with-body -X POST -H "Authorization: Bearer $API_TOKEN" -H "Content-Type: application/json" --data \'{"url":"https://example.com/webhook","confirm":true}\' "$BASE_URL/api/v1/requests/$REQUEST_ID/replay"'],
                    ['h' => 'Compare and bulk replay', 'p' => ['Select a request and open Compare requests. Pick another loaded request for side-by-side payload or header comparison. JSON object keys are normalized; array order is preserved. This is a positional line diff, not semantic change attribution.', 'Owners and members can open Bulk replay and select requests or replay current filter matches. The entire batch must contain 1–20 retained requests. Larger, empty or unavailable selections are rejected without queueing any delivery. Narrow filters first; matching uses the same bounded search as history. Each queued delivery has its own logs and bounded retries. Explicit authorization is required for every batch; never replay production events without considering duplicate side effects.']],
                    ['h' => 'History export', 'p' => ['History filters can be combined. Each export contains at most the newest 100 matching retained captures, not the entire history; the API exposes a cursor for older pages. JSON, NDJSON, cURL and CSV exports may contain sensitive data; review before sharing.']],
                ],
            ],
            'self-hosting' => [
                'title' => 'Self-hosting guide',
                'description' => 'Deployment notes for operators running the source edition.',
                'sections' => [
                    ['h' => 'Standalone local installation', 'p' => ['The standalone configuration includes PostgreSQL for local evaluation. Generate and persist APP_KEY before starting. Local database defaults are not safe for public deployment.'], 'code' => 'docker compose -f docker-compose.selfhost.yml up --build'],
                    ['h' => 'External PostgreSQL preview', 'p' => ['The default Compose configuration requires externally provisioned PostgreSQL. Set DATABASE_URL and MIGRATION_DATABASE_URL and supply a persistent APP_KEY via a secret manager. Back up both the database and encryption key: changing or losing the key makes encrypted data unreadable.'], 'code' => 'docker compose up --build'], 
                    ['h' => 'Tests', 'p' => ['Run the suite in the test container.'], 'code' => 'docker compose run --rm test'],
                    ['h' => 'Production responsibility', 'p' => ['Configure TLS and ingress, database backups, and monitoring. Monitor the queue worker and scheduler: retries and retention pruning depend on these processes. The Compose setup is a preview configuration, not a turnkey hardened production deployment. Never commit database credentials or encryption keys.']],
                    ['h' => 'Billing', 'p' => ['Self-hosted installs run with billing disabled by default (MANAGED_HOSTING=false); all core features are included without Stripe. The hosted Sandbox deployment is separate and runs in Stripe Test Mode only (no live paid charging).']],
                ],
            ],
            'privacy' => [
                'title' => 'Webhook Inspector Privacy Policy',
                'eyebrow' => 'Legal',
                'version' => 'Version dated 23 November 2026',
                'description' => 'This policy covers the hosted Webhook Inspector service at webhook-inspector.untasked.run. Drag’n Survey SAS operates the service. For privacy questions or requests, email webhook-inspector@untasked.app or write to Drag’n Survey SAS, 17 rue du Général Leclerc, 94000 Créteil, France (RCS Créteil 804 864 908).',
                'sections' => [
                    [
                        'h' => 'Account, billing and support information',
                        'p' => [
                            'Drag’n Survey SAS is the data controller for the information it uses to run accounts, manage subscriptions, provide support and protect the hosted service. We receive information from you, from your organisation when it gives you access, from your browser or device when you use the service, and from Stripe when you subscribe or manage billing.',
                        ],
                        'table' => [
                            'align' => 'left',
                            'headers' => ['Information', 'Why we use it', 'Legal basis under the GDPR'],
                            'rows' => [
                                [
                                    'Account email, organisation and workspace membership, plan and subscription status',
                                    'Give you access, apply plan limits and send service notices',
                                    'Performing our contract with you, or our legitimate interest in delivering the service to the organisation you represent',
                                ],
                                [
                                    'Billing contact, invoice details, Stripe customer and subscription identifiers, amounts and payment status',
                                    'Activate the subscription, reconcile payments, resolve billing issues and keep accounting records',
                                    'Contract performance or our legitimate interest in administering a business customer’s subscription, and compliance with tax and accounting obligations',
                                ],
                                [
                                    'Support emails, attachments and details you choose to share',
                                    'Answer questions, diagnose problems and follow up on your request',
                                    'Our legitimate interest in supporting users, or contract performance where you are the customer',
                                ],
                                [
                                    'Technical information such as IP addresses, timestamps, request metadata, errors and usage counts',
                                    'Diagnose faults, enforce usage limits and investigate abuse or unauthorised access',
                                    'Our legitimate interest in keeping the service secure and available',
                                ],
                            ],
                        ],
                        'after' => [
                            'We need an account identifier to provide account access and sufficient billing details to process a paid subscription. When requesting support, please send only the information we need to investigate. Redact credentials and unrelated personal information from examples and screenshots.',
                        ],
                    ],
                    [
                        'h' => 'Information inside webhooks',
                        'p' => [
                            'The organisation that configures an endpoint chooses which system sends events and what those events contain. A request can include personal information in its URL, query string, headers or body. For example, an order notification might contain a buyer’s email address even if the developer inspecting it only needs the order number.',
                            'When we handle personal data in webhook traffic on a customer’s instructions, we act as its processor, or as its subprocessor if the customer acts for another organisation. We receive and display events for inspection and, when instructed, transmit them to the destination the customer selects for replay or forwarding. The customer controls which destinations it authorises and whom it gives access to inspection links.',
                            'Use test data where possible. The Terms of Service require customers to redact secrets and arrange a data processing agreement with us before using the service to process personal data on their behalf. Contact webhook-inspector@untasked.app to arrange one.',
                        ],
                    ],
                    [
                        'h' => 'Who can receive information',
                        'p' => [
                            'Access to non-public webhook content is restricted to people and service providers who need it to operate, secure or support the service. Providers of hosting, infrastructure and email services may handle information for these purposes. If you ask us to troubleshoot a particular event, authorised support personnel may need to inspect it. We may also disclose information where the law requires it or where necessary to establish, exercise or defend legal claims.',
                            'Stripe handles checkout and the Manage billing portal. Payment details entered there go directly to Stripe. We receive the subscription and transaction information needed to administer your account. Stripe also processes some information for its own purposes, including fraud prevention and compliance.',
                        ],
                        'links' => [
                            ['label' => 'Stripe Privacy Policy', 'href' => 'https://stripe.com/privacy'],
                        ],
                    ],
                    [
                        'h' => 'International transfers',
                        'p' => [
                            'Providers may process personal data outside the European Economic Area. Where the GDPR applies to a transfer, we rely on an applicable European Commission adequacy decision or appropriate safeguards, such as the Commission’s standard contractual clauses with supplementary measures where needed. To ask which safeguards apply to your data or obtain a copy, email webhook-inspector@untasked.app.',
                        ],
                    ],
                    [
                        'h' => 'How long we keep information',
                        'p' => [],
                        'list' => [
                            ['label' => 'Webhook events.', 'text' => 'Captured requests are temporary debugging records. Their availability depends on the retention and usage limits of the applicable plan. If you need a specific event removed, contact us with the endpoint or event identifier. Please omit the payload itself.'],
                            ['label' => 'Account information.', 'text' => 'We keep the information needed to operate an active account. Cancelling a paid subscription stops renewal. To request erasure of account data, contact us separately.'],
                            ['label' => 'Support and security records.', 'text' => 'We keep information for the time needed to resolve the issue, investigate related incidents or establish, exercise or defend a legal claim. The nature of the issue and the applicable limitation period determine how long the relevant records are needed.'],
                            ['label' => 'Invoices and accounting records.', 'text' => 'We retain the records we are legally required to keep under French accounting law for ten years from the close of the relevant financial year.'],
                        ],
                    ],
                    [
                        'h' => 'Cookies and browser storage',
                        'p' => [
                            'The hosted service may use cookies or browser storage for sign-in, session continuity and security. Blocking essential storage can prevent sign-in or interrupt an active session. Stripe explains the technologies it uses when you open checkout or the billing portal.',
                        ],
                        'links' => [
                            ['label' => 'Stripe Cookie Policy', 'href' => 'https://stripe.com/legal/cookies-policy'],
                        ],
                    ],
                    [
                        'h' => 'Your privacy rights',
                        'p' => [
                            'For personal data we control, you may ask to access, correct or erase your information, restrict its processing, or receive a portable copy where the legal conditions apply. You may object to processing based on legitimate interests for reasons relating to your situation. Where processing relies on consent, you may withdraw it at any time.',
                            'Email webhook-inspector@untasked.app and tell us which account or interaction your request concerns. If we reasonably doubt your identity, we may ask for the information needed to verify it. We normally respond within one month. If a request is complex and more time is needed, we will explain the extension within that first month.',
                            'If your information appeared in a webhook sent by another organisation, contact that organisation first. It can identify the event and instruct us to assist. If you contact us directly, include the organisation’s name and any non-sensitive event reference you already have.',
                            'You may lodge a complaint with the French data protection authority, the CNIL, or with the supervisory authority where you live or work in the EU or where the alleged infringement occurred.',
                        ],
                        'links' => [
                            ['label' => 'Contact the CNIL', 'href' => 'https://www.cnil.fr/en/contact-us'],
                        ],
                    ],
                    [
                        'h' => 'Changes to this policy',
                        'p' => [
                            'We will update the version date when this policy changes and draw material changes to your attention through the service or by email.',
                        ],
                    ],
                ],
            ],
            'terms' => [
                'title' => 'Webhook Inspector Terms of Service',
                'eyebrow' => 'Legal',
                'version' => 'Version dated 23 November 2026',
                'description' => 'These Terms govern the hosted Webhook Inspector service at webhook-inspector.untasked.run. Drag’n Survey SAS operates the service (“we”, “us”). Our registered office is at 17 rue du Général Leclerc, 94000 Créteil, France. We are registered with the Créteil Trade and Companies Register under number 804 864 908. Contact us at webhook-inspector@untasked.app for support, billing or legal notices.',
                'sections' => [
                    [
                        'h' => '1. Who can use the service',
                        'p' => [
                            'Webhook Inspector is for business and professional use. By accepting these Terms, you enter into an agreement with Drag’n Survey SAS. If you accept on behalf of an organisation, you confirm that you have authority to bind it. In that case, “you” means that organisation. These Terms cover the hosted service. Any software you obtain under an open-source licence remains subject to that licence.',
                        ],
                    ],
                    [
                        'h' => '2. Plans and subscriptions',
                        'p' => [
                            'Webhook Inspector gives you hosted endpoints for receiving and inspecting webhook requests. Your order identifies your plan, its features and the usage limits that apply to your subscription.',
                        ],
                        'table' => [
                            'headers' => ['Hosted plan', 'Monthly subscription price'],
                            'rows' => [
                                ['Developer', 'USD 9'],
                                ['Pro', 'USD 29'],
                                ['Team', 'USD 79'],
                            ],
                        ],
                        'after' => [
                            'The total due, including any applicable taxes, is displayed before you subscribe. Paid subscriptions are billed monthly in advance. By subscribing, you authorise a recurring charge to your payment method each month until you cancel. There is no early-payment discount. We will give you at least 30 days’ notice of a price increase. It will first apply at a renewal after that notice period.',
                            'Stripe handles payments. If checkout identifies a merchant of record, that merchant’s purchase terms govern payment, taxes and refunds for that transaction. Its details and billing support information appear at checkout or on your receipt. Drag’n Survey SAS remains responsible for operating Webhook Inspector and providing product support.',
                        ],
                    ],
                    [
                        'h' => '3. Cancellation, refunds and failed payments',
                        'p' => [
                            'To stop automatic renewal, open Manage billing in Webhook Inspector and cancel your subscription in the Stripe customer portal before your next renewal. You retain access through the last day of your current paid billing period. If you are charged for a renewal after cancelling in time, contact us for a refund. For a duplicate or incorrect charge, include the invoice or receipt number so we can investigate.',
                            'We do not prorate the current month for voluntary cancellation, unless the purchase terms at checkout or applicable law require a refund. If a payment fails, we may suspend paid features after notifying you and giving you an opportunity to update your payment details. For overdue professional invoices payable directly to Drag’n Survey SAS to which French late-payment rules apply, interest accrues without reminder from the day after the due date at the applicable European Central Bank refinancing rate plus ten percentage points. A fixed EUR 40 recovery charge applies per overdue invoice.',
                        ],
                    ],
                    [
                        'h' => '4. Webhook traffic and acceptable use',
                        'p' => [
                            'Use the service only with systems and data you are authorised to test. You are responsible for your users, your account credentials, the endpoints you share and the destinations you select. Treat endpoint and inspection links as confidential. Notify us promptly if you suspect unauthorised access.',
                            'Use test data and redact secrets before sending requests. Headers, query strings and request bodies can contain credentials even when the event itself looks harmless. Do not send live passwords, private keys, access tokens, full payment card details or sensitive health data. If you accidentally send a live credential, revoke it at its source.',
                            'Do not use the service to intercept someone else’s traffic, send unsolicited or harmful requests, evade plan limits or disrupt other systems. If you replay or forward an event, you are responsible for the resulting request. For example, replaying a payment event against a live system can trigger the same business action twice.',
                        ],
                    ],
                    [
                        'h' => '5. Customer data and confidentiality',
                        'p' => [
                            'You retain your rights in the data you send. You authorise us and the providers we use to receive, store, display and otherwise process it as needed to operate, secure and support the service, follow your instructions or comply with law. We will treat non-public webhook contents as confidential and restrict access to people and providers who need it for those purposes.',
                            'You are responsible for the lawful collection and disclosure of your webhook data. If your intended use requires us to process personal data on your behalf, contact us to put a data processing agreement in place before sending that data. We use account, billing and support information to administer the service and respond to you.',
                            'Captured requests are temporary debugging records. Keep your own copy of any event you need to retain, including before cancelling or changing plans. Events may expire under plan limits, and you may lose access when the subscription ends.',
                        ],
                    ],
                    [
                        'h' => '6. Service operation and suspension',
                        'p' => [
                            'We will use reasonable care in operating the service. We do not guarantee uninterrupted availability, delivery of every request or recovery of expired events. You are responsible for keeping the original event at its source and for checking the outcome of any replay.',
                            'We may restrict access where reasonably necessary to contain abuse, protect the service or comply with law. Where practicable, we will explain the restriction and restore access once its cause has been resolved. Either party may terminate for a material breach that remains unresolved 30 days after written notice. If we permanently discontinue a paid plan before the end of your prepaid period, we will arrange a proportionate refund for the unused period.',
                        ],
                    ],
                    [
                        'h' => '7. Intellectual property and liability',
                        'p' => [
                            'You may use the hosted service for your professional activities within your plan limits. Drag’n Survey SAS and its licensors retain their rights in the software, branding and service. Your use gives us no ownership of your payloads or integrations.',
                            'Each party is responsible for direct, foreseeable loss resulting from its breach of these Terms. To the extent permitted by law, neither party is liable for indirect loss. Nothing in these Terms limits liability for fraud, wilful misconduct, gross negligence or any liability that applicable law prevents the parties from limiting.',
                        ],
                    ],
                    [
                        'h' => '8. Changes and disputes',
                        'p' => [
                            'We will give you at least 30 days’ notice of material changes to these Terms by email or through the service. You may cancel before the changes take effect. Where an earlier change is necessary to comply with law or address an immediate security risk, we will explain the change as soon as reasonably practicable.',
                            'French law governs these Terms. If a dispute arises, either party may contact the other to seek a resolution. Either party may bring a claim before the courts having jurisdiction under applicable law.',
                        ],
                    ],
                ],
            ],
            'security' => [
                'title' => 'Security and support',
                'description' => 'How to get help without exposing webhook data.',
                'sections' => [
                    ['h' => 'General support', 'p' => ['Start with the documentation. The public source repository is for non-sensitive project discussion only. Do not include request bodies, receiver URLs, tokens or account details.']],
                    ['h' => 'Sensitive reports', 'p' => ['A dedicated private vulnerability reporting channel has not been published. Do not post vulnerability details or customer data in public issues or discussions. If a receiver URL is exposed, delete and recreate the endpoint. Revoke exposed project API tokens in the dashboard.']],
                ],
            ],
        ];
    }

    public function show(string $slug): Response
    {
        $pages = $this->pages();
        abort_unless(isset($pages[$slug]), 404);

        return Inertia::render('Info', ['page' => ['slug' => $slug, ...$pages[$slug]]]);
    }
}
