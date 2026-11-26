# Milestones 2–3: implementation handoff

This branch is a review candidate, **not a deployed or production-ready release**. Do not merge or deploy until milestone review. Existing stable service is not evidence for this branch. This document accompanies the exact source artifact and commit referenced in the task submission.

## Scope and review focus

This slice addresses secure forwarding/replay and delivery attempts, history filtering and export, monthly quota accounting, retention, receiver URL and application-key configuration, billing integration, user interface, and self-hosting documentation. Inspect the code and tests for exact supported behavior and limitations; it does not establish commercial readiness.

The forwarding path is deliberately fail-closed and IPv4-only. It rejects unsupported or unsafe destinations and uses DNS validation with connect-time pinning, disables redirects, bounds request/response handling and timeouts, and avoids forwarding captured credentials by default. Destination URLs are treated as sensitive: stored encrypted, not disclosed in ordinary delivery responses, and must not be placed in handoffs or screenshots. Replay requires explicit side-effect confirmation; it preserves captured method and body, selected non-sensitive headers and sanitized captured query. Only the destination is configurable: arbitrary method, body, header or query overrides are not supported. The destination itself cannot contain a query or fragment.

History combines method, from/to date, content-type and minimum/maximum size filters. JSON, NDJSON and cURL exports include at most the newest 100 matching retained records, with a cursor for older pages; payloads can contain secrets. Monthly usage accounting is per account and UTC month and is separate from history visibility and deletion; review limits and scheduler/worker configuration prior to enabling ingestion. Paid billing is unavailable until a provider is configured; mock tests are not proof of live payments or entitlements.

## Verification and evidence

| Check | Result / scope |
| --- | --- |
| `docker compose build test && docker compose run --rm test` | Passed in the test environment: 16 tests, 300 assertions, 92.7% reported backend line coverage. This is not a production security audit. |
| Browser smoke | Synthetic desktop and mobile screenshots and `results.json` are included in the shared evidence artifact. It reports successful login, synthetic capture HTTP 202, visible payload, an invalid-private-target validation message, no collected browser errors, and no horizontal overflow at 390px. This is not a deployed-candidate smoke test. The screenshots do not show a readable receive URL or endpoint list, so they do not prove URL visibility/copy or navigation. The replay validation message appears under endpoint settings rather than next to replay: this is a usability issue for review. |
| Provider behavior | Automated tests use a mock provider; no real Stripe checkout, portal, webhook event or paid subscription was verified. |
| Production | Not merged or deployed; candidate, production queue/scheduler, DNS and external HTTP behavior must be verified independently before release. |
| Open source | Local self-hosting and contribution documents are included; publication to GitHub and an independent clean-host installation were not verified. |

## Release gates / outstanding work

1. Automated tests cover tenant isolation, combined history filters/exports, quota rollover, encryption migration, forwarding/replay, retries, circuit breaker, purge and SafeHttp policy with fake transport. Independently review SSRF defenses, DNS rebind behavior, mixed answers, redirects and bounded responses using real-network tests; verify tenant isolation and export handling of sensitive data.
2. Verify migrations and encrypted destination migration against representative production data; retain a secure stable APP_KEY and rehearse restore/rollback. The key is not a generated per-boot value.
3. Verify real async worker, delayed retry and periodic retention execution on the intended deployment topology. Test quota concurrency against PostgreSQL.
4. Review UI and synthetic screenshots, then smoke test desktop/mobile, keyboard and failed states against the actual candidate. Confirm no secrets appear in browser output or evidence.
5. Configure authorized provider credentials only through the deployment secret mechanism; perform an end-to-end Stripe live-mode verification, webhook verification and lifecycle reconciliation before representing billing as operational. Do not expose keys or real customer data in review artifacts.
6. Independently verify licensing, Docker Compose installation, backups, monitoring, data deletion, legal copy and open-source repository publication before making launch claims.
