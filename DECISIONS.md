# Project decisions

## Blueprint and rationale
- Blueprint: `laravel-inertia-vue`
- Rationale: Persistent multi-tenant SaaS (accounts, endpoints, captured requests, billing). Laravel + Inertia + Vue + PostgreSQL per mission preference.

## Runtimes and versions
- laravel: 13.0.0
- php: 8.4.0
- vue: 3.5.0
- inertia: 3.0.0
- tailwind: 4.0.0

## Data, storage and deploy
- Data: Hosted Postgres (DATABASE_URL runtime, MIGRATION_DATABASE_URL for migrations in CMD). Payload bodies stored encrypted (Laravel Crypt) in Postgres; bodies capped (default 1MB). Queue driver: database (no Redis on the single Fly machine; Redis optional for self-host). Retention purge via scheduled command.
- Deploy: Single Fly machine via untasked.yaml; repo webhook-inspector linked to hosted app 01m6ztxwn14yesr13h5jrqnqez. Planner owns release_deploy/promote/publish.

## UI kit, icons and tokens
- UI kit: none
- Icon family: lucide
- Tokens: follow the starter design tokens; adapt identity to the product.

## Capabilities and licenses
- Tailwind 4 via @tailwindcss/vite (starter)
- Real-time: polling/SSE endpoint (no paid websocket service)
- Stripe via stripe/stripe-php (MIT) only when STRIPE_SECRET present; billing degrades gracefully
- SSRF guard: resolve DNS, block private/loopback/link-local/metadata IPs, http(s) only

## Contracts and exceptions
- (none)

## Validation and known limitations
- Validation: Pest/PHPUnit feature tests, >=80% backend line coverage gate.
- Limitations: Single Fly machine; no platform Redis/worker/scheduler by default. Migrations run in Dockerfile CMD.
- Guides in use:
- untasked-deploy-contract
- laravel-deployment
- laravel-migrations

## Next delivery
Ship the first useful vertical slice and verify the full user flow.
