# Project decisions

## Blueprint and rationale
- Blueprint: `laravel-inertia-vue`
- Rationale: Not needed

## Runtimes and versions
- laravel: 13.0.0
- php: 8.4.0
- vue: 3.5.0
- inertia: 3.0.0
- tailwind: 4.0.0

## Data, storage and deploy
- Data: Use Untasked Postgres/storage when data must persist. Do not use localStorage as the database.
- Deploy: hosted-app

## UI kit, icons and tokens
- UI kit: shadcn-vue
- Icon family: lucide
- Tokens: follow the starter design tokens; adapt identity to the product.

## Capabilities and licenses
- (none yet)

## Contracts and exceptions
- (none)

## Validation and known limitations
- Validation: docker compose -f compose.yaml config; docker compose run --rm test php -d pcov.enabled=1 vendor/bin/pest --coverage --min=80 --coverage-text
- Limitations: Single Fly machine; no platform Redis/worker/scheduler by default. Migrations run in Dockerfile CMD.
- Guides in use:
- laravel-core
- laravel-validation
- laravel-auth
- laravel-eloquent
- laravel-migrations
- laravel-jobs
- laravel-events
- laravel-storage
- laravel-http
- laravel-frontend
- laravel-caching
- laravel-testing
- laravel-deployment
- vue-core
- inertia-core
- tailwind-core
- shadcn-vue-core
- lucide-core
- playwright-core
- untasked-app-create
- untasked-app-up
- untasked-repo-release
- untasked-deploy-contract
- untasked-domains
- untasked-storage
- untasked-secrets

## Next delivery
Ship the first useful vertical slice and verify the full user flow.
