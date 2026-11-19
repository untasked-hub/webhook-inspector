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
                'billing_note' => Billing::enabled() ? 'Checkout is available.' : 'Paid checkout is currently unavailable on this deployment: billing is not configured.',
            ],
            'docs' => [
                'title' => 'Documentation',
                'description' => 'Capture, inspect, forward and replay webhooks.',
                'sections' => [
                    ['h' => 'Quick start', 'p' => ['Register, create a project and endpoint, copy its private receiver URL, then send a test request. Set RECEIVER_URL privately in your shell; never publish it. Select the captured request in the dashboard to inspect it.'], 'code' => "# Set RECEIVER_URL privately; never publish it.\ncurl -X POST \"\$RECEIVER_URL\" \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"hello\":\"world\"}'"],
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
                'sections' => [['h' => 'Data', 'p' => ['Captured webhook payloads are treated as sensitive and scoped to your account. Expired data is removed by the scheduled pruning job when it is running. Account email and hashed password are stored for authentication.', 'This is a draft policy pending legal review.']]],
            ],
            'terms' => [
                'title' => 'Terms of service',
                'description' => 'Terms for using Webhook Inspector.',
                'sections' => [['h' => 'Use', 'p' => ['Do not use the service to attack third parties or send unlawful content. Forwarding to internal networks is blocked. The service is provided as is.', 'This is a draft pending legal review.']]],
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
