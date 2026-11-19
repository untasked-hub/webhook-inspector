# Contributing

Thank you for helping improve Webhook Inspector. Changes should be focused, accompanied by tests, and avoid exposing customer payloads, receiver URLs, credentials or private data in commits and issue reports. Use synthetic fixtures only.

## Development

Provision a disposable PostgreSQL database and follow the preview instructions in [README.md](README.md). Never use production credentials in tests. Run `docker compose run --rm test` before submitting; the image enforces at least 80% backend line coverage. Frontend assets build as part of the Docker image. Include reproduction steps, actual commands and limitations in a change description.

Review changes to tenant isolation, rate limits, retention, encryption, queue behavior, URL validation and network egress particularly carefully. Never trust a DNS validation performed separately from a connection unless the validated IP is pinned for that connection; redirects must remain disabled. Consider concurrency and failure cases as well as the happy path. Tests using mocks do not establish real network, payment or production behavior.

Report security vulnerabilities privately to the repository maintainers rather than posting exploit details publicly. Do not include secrets, receiver URLs or captured real requests in a report.

By contributing you agree that your contribution may be distributed under the repository license. Issues and pull requests are reviewed by maintainers; acceptance is not guaranteed.
