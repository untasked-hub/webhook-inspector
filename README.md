# Webhook Inspector

Inspect, debug, and replay webhooks in seconds.

- **Managed service:** The live hosted edition is available at [https://webhook-inspector.untasked.run](https://webhook-inspector.untasked.run).
- **Self-hosted / source edition:** This repository contains the open-source Laravel/Vue application—authenticated projects and endpoints, encrypted capture, polling inspector, filtered history, bounded exports, queued replay and forwarding, delivery attempts, and scheduled retention. Treat receiver URLs and payloads as sensitive.

**Self-host & deployment scope:** Independent self-hosted production deployments require operator setup and verification (external TLS, ingress, Postgres backups, `APP_KEY` management, monitoring, and any third-party billing credentials). Draft legal pages are templates, not legal advice. See [implementation notes](IMPLEMENTATION-NOTES.md) for tested and untested behavior.

## Self-host locally: capture your first webhook

From the root of a checkout, with Docker running, Compose v2 installed, and `curl` available:

```sh
docker compose -f docker-compose.selfhost.yml up --build -d
```

No `.env` setup is needed for this local quickstart: Compose supplies the local database settings and the entrypoint generates an encryption key on first boot. No external database, Stripe account, or cloud credentials are required. Once the containers start, check readiness with `curl --fail http://localhost:8080/up` (expect HTTP 200). Open <http://localhost:8080>, register a local account, then create a project and endpoint. Copy the receiver URL shown in the endpoint panel and send a synthetic event from the same machine:

```sh
read -r RECEIVER_URL # Paste the receiver URL, then press Enter.
curl --fail-with-body -i --json '{"event":"quickstart.ping","source":"local-demo"}' "$RECEIVER_URL"
unset RECEIVER_URL
```

Open the new request in the inspector and verify that the method is `POST` and the payload contains `quickstart.ping`. The inspector polls for new requests; allow a few seconds. The URL is a bearer capability: keep it out of screenshots, logs, and public issues. Use synthetic data for this exercise.

If the page is unavailable, run `docker compose -f docker-compose.selfhost.yml ps` and `docker compose -f docker-compose.selfhost.yml logs --tail=80 web postgres` locally. Sanitize any logs before sharing. Port 8080 must be free. This setup binds to loopback; external webhook providers cannot reach it.

Stop with `docker compose -f docker-compose.selfhost.yml down`. This preserves both named volumes. **Do not add `-v` unless you want to permanently delete the local database and encryption key.** Keep a secure backup of both for recovery. This quickstart is not an internet-facing production configuration.

## Developer preview with external PostgreSQL

Prerequisites: Docker Compose, an externally provisioned PostgreSQL database with an `app` schema, a runtime role with table DML privileges and a migration role with schema privileges. Set `DATABASE_URL` and `MIGRATION_DATABASE_URL` in your environment using your own credentials; do not commit them. Then:

```sh
docker compose up --build
# Run in a second terminal:
docker compose run --rm test
```

Open `http://127.0.0.1:8080`, register, create a project and endpoint, then send a test request to its displayed receiver URL. This URL is a bearer capability; anyone who knows it can submit. Delete and recreate an endpoint to rotate it. Never paste the URL into public issues or screenshots.

This Compose file is a preview, **not** a turnkey hardened production deployment. It defaults to local mode, persisting a generated APP_KEY in a Docker volume. If that volume is removed, previously encrypted content is unreadable. For a durable installation generate a unique Laravel `base64:` 32-byte APP_KEY, store and back it up securely outside the repository, and inject the same key on every start. Loss or rotation of the key prevents decryption of previous captures and destination URLs. Configure TLS, network ingress, Postgres backups and monitoring yourself. Production requires `APP_KEY`; migrations use `MIGRATION_DATABASE_URL`, not the runtime role. The entrypoint starts a database queue worker and Laravel scheduler; ensure both stay healthy and account for single-process limitations before deploying.

## Security and behavior

Bodies, captured headers/query and destination URLs are encrypted at rest. Redaction covers selected credential-like header and query names, not payload bodies or every secret: inspect exports before sharing. Replay preserves method, body, selected headers and sanitized captured query; credential headers and credential-like query parameters are not forwarded. Replay can have external side effects and requires explicit confirmation. Only public IPv4 `http`/`https` destinations on ports 80, 443, 8080 and 8443 are accepted. All IPv4 A answers must pass validation; dual-stack hosts use a validated public IPv4, IPv6-only hosts fail closed, the validated address is pinned at connection, and redirects are disabled. Query-bearing destination URLs are rejected. Review `app/Support/SafeHttp.php` and test in your environment before using with sensitive or untrusted workloads.

History supports combined filters. JSON, NDJSON and cURL exports are limited to at most 100 matching retained captures per response and may contain sensitive payloads; use the history API cursor for older pages. Retention removes expired rows only while the scheduler is healthy. Self-hosted mode includes the core features without a paid subscription. In managed deployments, usage quotas are enforced per account per UTC calendar month, and paid checkout/billing portal flows are enabled only when a payment provider is configured and verified (configuration alone is not proof of a live payment integration).

### Operational checks

`GET /api/health` is a public, unauthenticated readiness probe for load balancers and uptime monitors. It returns only coarse states and no versions, hostnames or error text:

```json
{"status":"ok","checks":{"database":"ok","worker":"ok","scheduler":"ok"}}
```

- `status` is `ok` when every check passes, `degraded` when the database answers but a background process is not healthy, and `unavailable` when the database cannot be reached.
- The response is HTTP `503` only for `unavailable`; a `degraded` state stays `200` so a stalled worker does not cause restart loops that hide the real fault.
- `worker` and `scheduler` are `ok`, `stale` (last heartbeat older than 3 minutes) or `unknown` (never reported, or the cache store is unreachable). The queue worker writes its heartbeat from its loop and the scheduler writes one each minute, so `unknown` after a few minutes of uptime means the process is not running.
- Responses are `Cache-Control: no-store`. The endpoint is throttled to 60 requests per minute per client.

Known limitations: heartbeats live in the configured cache store, so a Redis outage reports `unknown` for both processes even when they run; the probe does not verify the Stripe, object-storage or outbound-forwarding dependencies; `/up` remains the container health check and only confirms the web process is serving.

### Conditional forwarding and destination health

In **Endpoint settings**, enable automatic forwarding and optionally set an HTTP method, an exact header value, or a JSON dot-path and exact string value. All configured conditions must match. Header names are case-insensitive; header values and JSON strings are case-sensitive. For example, `POST` + `X-Event: orders` + `data.type` = `paid` forwards only matching requests. A path such as `items.0.type` can address an array item. Missing paths, invalid JSON, and non-string values do not match a JSON string condition. Credential-like header names cannot be used in filters. A non-match is still captured, and explicit replay is independent of forwarding conditions.

On existing destinations, leave the URL blank to keep the stored URL while editing conditions or toggling forwarding. Clearing every condition restores unconditional forwarding. Condition values are encrypted at rest. Do not put credentials in rules.

The destination badge reports recent **automatic forwarding** outcomes, not an active reachability check. **Awaiting first delivery** means no retained attempt for the current destination; **Healthy** means the latest attempted delivery succeeded; **Delivery issues** indicates failures or a blocked/retrying delivery; **Temporarily paused** means the circuit breaker is suppressing new forwards; **Forwarding off** means it is disabled in settings. Changing destination starts fresh. History and health evidence can expire with retention.

## Contributing and license

See [CONTRIBUTING.md](CONTRIBUTING.md) and [LICENSE](LICENSE).

**Built and maintained by Untasked agents**

This project is developed and maintained by autonomous AI agents operating on [Untasked](https://untasked.app). Issues and pull requests are reviewed and handled by Untasked agents.

## Real-time updates (how it works)

The inspector uses **short polling**, not WebSockets or SSE: while the dashboard tab is visible it calls `GET /api/endpoints/{id}/requests` every 2 seconds (paused when the tab is hidden or using the Live/Pause toggle). New captures therefore appear within ~2 s without reloading. Polling keeps the self-hosted edition dependency-free (no Redis/Pusher required).

## History search, filters and export

`GET /api/endpoints/{id}/requests` accepts `method`, `q` (case-insensitive text search across body, headers and query; payloads are encrypted at rest so search decrypts the newest 1,000 filtered rows), `status` (`none|queued|retrying|succeeded|failed|blocked` delivery outcome), `from`/`to`, `content_type`, `min_size`/`max_size`, and `format=json|ndjson|curl|csv`. Exports contain the newest 100 matching retained requests and are tenant-scoped. CSV cells starting with `= + - @` are prefixed with `'` to prevent spreadsheet formula injection.

## Projects and analytics

Projects can be created, renamed (`PATCH /api/projects/{id}`) and deleted (`DELETE /api/projects/{id}`, cascades to endpoints and captures). `GET /api/analytics?days=1..90` returns requests per day and per endpoint (retained captures), delivery outcomes, and monthly plan usage vs limit; the dashboard shows it under **Analytics**.

## Custom responses and signature diagnostics

In the dashboard, expand **Receiver response & signature verification**. Configure a supported status code, `application/json` or `text/plain`, and a response body (16 KiB maximum; ignored for 204). Errors still capture requests and may trigger retries by the caller. No arbitrary headers or redirects are allowed. Receiver URLs use `/c/{token}`; `/w/{token}` is an equivalent alias. Use the exact private URL shown in the dashboard.

Optional signing secrets and custom bodies are encrypted at rest. Provider modes: `auto`, `stripe`, `github`, `slack`, `hmac-sha256`. Stripe checks `Stripe-Signature` (`t=...,v1=...`); GitHub checks `X-Hub-Signature-256` (`sha256=<hex>`); Slack checks `X-Slack-Signature` with `X-Slack-Request-Timestamp`; generic checks `X-Webhook-Signature` (hex HMAC-SHA256). Stripe/Slack permit five minutes of timestamp skew. Verification is diagnostic, **not** an ingestion gate: invalid or missing signatures remain visible with a status badge. Blank secret preserves configuration; explicitly remove to clear it. Secret values are never returned to clients; signature headers are redacted before storage/export. Previously captured requests are not retroactively verified.

## Project collaboration

Owners use **Manage project team** to create 24-hour, single-use invitation links, change `member`/`viewer` roles, remove members and revoke unused invitations. Only a SHA-256 token hash is stored. The private token is in the URL fragment (not server access logs); sign in first and then open the link to explicitly accept. Copy the link when created: it cannot be retrieved later. Share privately; possession permits joining. Pending invitations and members are capped at 50 per project. Membership numbers avoid disclosing account identities; owners should privately track link recipients.

- **Owner:** manages projects, endpoints and team access.
- **Member:** reads/exports captures, configures endpoints, clears history, forwards/replays/retries.
- **Viewer:** reads/exports captures and delivery logs; cannot modify data.

Billing and analytics stay account-scoped. Inbound usage and retention ceilings belong to the project owner. Shared project deletion cascades through memberships and invitations. Role and tenant authorization is rechecked on every API request. Self-hosted collaboration is included without billing.

## Project API tokens and REST API

In **Project API tokens**, generate a named `read` or `read+replay` credential. Store it immediately: the raw token is shown once and only its SHA-256 hash is stored. Owners list/revoke all project tokens; members manage their own; viewers cannot mint tokens. Current membership is rechecked on every call: removing a member blocks their tokens, and downgrading to viewer blocks replay. Token creation is capped at 50 per project. Never publish tokens or use them in URL parameters.

Set `BASE_URL` to your deployment origin, and set `API_TOKEN`, `PROJECT_ID`, `ENDPOINT_ID`, and `REQUEST_ID` privately in your shell:

```sh
# Endpoint metadata (does not expose private receive URLs or signing secrets)
curl --fail-with-body -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/projects/$PROJECT_ID/endpoints"
# Newest retained metadata (up to 100); paginate with ?before=<next_before>
curl --fail-with-body -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/endpoints/$ENDPOINT_ID/requests"
# Request metadata + payload: body_base64 contains the exact bytes
curl --fail-with-body -H "Authorization: Bearer $API_TOKEN" "$BASE_URL/api/v1/requests/$REQUEST_ID"
# Side effects! Requires read+replay; 202 returns a queued delivery id, not success.
curl --fail-with-body -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data '{"url":"https://example.com/webhook","confirm":true}' "$BASE_URL/api/v1/requests/$REQUEST_ID/replay"
```

REST always returns JSON. History accepts the same filters as above except export format; poll the first page and deduplicate by id. Rate limits are shared at 120 calls/minute per project plus 180/minute per source IP. Errors: 401 invalid/missing/revoked token, 403 insufficient role/scope, 404 inaccessible/expired resource, 422 invalid input, 429 rate limit. Replay uses the same SafeHttp validation/pinning at enqueue and delivery time as the dashboard. Do not automatically retry ambiguous replay failures: each call creates a delivery. Delivery logs remain available in the dashboard.

## Side-by-side diff and bulk replay

Open **Compare requests** in the inspector to compare the current request with another loaded history entry. Payload/header views highlight changed positional lines; JSON keys are sorted and array order is preserved. This is not a semantic diff algorithm. Load older history to compare older entries.

Owners/members can open **Bulk replay**, choose selected requests or all current filter matches, configure a public destination and explicitly authorize side effects. The whole batch must contain 1–20 retained requests or it is rejected without queueing anything. Larger result sets are never silently truncated. Encrypted search retains its 1,000-candidate window. Each request becomes a separate delivery with its own logs/retry budget. Batch creation is limited to five calls/minute per source IP. These features are included in self-hosted mode.

## Host handling

`X-Forwarded-Host` is never trusted. `X-Untasked-Original-Host` (set by the Untasked hosting router) is honoured only when it matches `TRUSTED_ORIGINAL_HOSTS` (comma-separated, default `*.untasked.run`). Self-hosters behind their own proxy should leave it unset or set it to their own domain.

## Self-hosted backups and recovery

Compose volumes persist across normal restarts; they are not backups. Operators must schedule encrypted, off-host PostgreSQL backups and separately preserve the matching Laravel `APP_KEY` (or the generated key volume). To recover, restore the database into an isolated installation and supply that same key before starting the app. Verify that a previously captured synthetic payload decrypts and that forwarding remains disabled during any restore drill. Losing the matching key makes encrypted data unreadable. Retention pruning does not erase independent backups; set a separate backup expiry policy. No automated backup or restore guarantee is provided by the local Compose setup.

Core capture, inspection, replay, forwarding, retention, and exports require no subscription.
