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
                'sections' => [['h' => 'Managed plans', 'p' => ['Request quotas are enforced per account each calendar month. Paid checkout is only available once billing credentials are configured on the server.']]],
                'plans' => collect(config('billing.plans'))->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name'], 'price' => $p['price'], 'requests' => $p['requests'], 'retention' => $p['retention']])->values()->all(),
                'billing_enabled' => Billing::enabled(),
                'billing_mode' => Billing::enabled() ? config('billing.stripe_mode') : null,
                'signed_in' => auth()->check(),
                'billing_note' => !Billing::enabled()
                    ? 'Paid checkout is currently unavailable on this deployment: billing is not configured.'
                    : (config('billing.stripe_mode') === 'test'
                        ? 'Stripe test mode. Use test payment details only; no real payment will be taken.'
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
                    ['h' => 'Billing', 'p' => ['Self-hosted installs have no billing by default; all core features are included.']],
                ],
            ],
            'privacy' => [
                'title' => 'Privacy policy',
                'description' => 'How Webhook Inspector handles captured data.',
                'sections' => [
                    ['h' => 'Information processed', 'p' => ['We store account email and a password hash for authentication. The inspector stores webhook bodies, request metadata, headers and query parameters for debugging. Credential redaction is limited: payloads and exports may still contain sensitive information.']],
                    ['h' => 'Access and storage', 'p' => ['Captured bodies and configured destination URLs are encrypted at rest. Authorized project collaborators can access retained requests according to their roles. If you enable forwarding or replay, your selected destination receives request data.']],
                    ['h' => 'Hosting and payment providers', 'p' => ['The managed deployment uses Untasked hosting infrastructure. If enabled, paid checkout and the billing portal use Stripe; payment details are entered there, not in the inspector. A formal subprocessor list and associated terms are pending owner review.']],
                    ['h' => 'Retention and deletion', 'p' => ['Scheduled pruning removes expired captures from the live database. Deleting project data or pruning live captures does not necessarily remove backup copies at the same time. Backup retention and a process for privacy access or deletion requests are pending owner review. Self-hosted operators control their own data and backups.']],
                ],
            ],
            'terms' => [
                'title' => 'Terms of service',
                'description' => 'Terms for using Webhook Inspector.',
                'sections' => [
                    ['h' => 'Acceptable use', 'p' => ['Only capture, forward or replay events you are authorized to handle. Do not send unlawful content or use the service to interfere with other systems. Replay can repeat actions on a destination; review requests before sending.']],
                    ['h' => 'Credentials and access', 'p' => ['Keep receiver URLs, signing secrets, invitation links and API tokens private. Grant collaborators only the access they need, and review exported data before sharing it.']],
                    ['h' => 'Managed plans and availability', 'p' => ['Managed plans are subject to published request and retention limits. See Pricing for current checkout availability and quota behavior. Paid terms, refunds and service-level commitments are not finalized. Keep copies of data you need beyond the retention window.']],
                    ['h' => 'Self-hosting', 'p' => ['The source license covers use of the software. Self-hosted operators are responsible for securing their installation and setting their own retention and backup policies.']],
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
