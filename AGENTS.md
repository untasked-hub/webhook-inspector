# Laravel 13 + Vue + Inertia 3 starter

PHP and Composer run inside Docker — not on the E2B host. Stack matches Laravel’s official Vue kit direction: Laravel 13, Inertia 3, Vue 3 + TypeScript, Tailwind 4, shadcn-vue, Lucide.

1. Call `app_decision_record` if DECISIONS.md needs updates (`ui_kit: shadcn-vue`, `icon_family: lucide` separately).
2. Load guides with `app_guide_get` (start with `laravel-core`, then topic guides). Prefer https://laravel.com/docs/13.x.
3. **Brand the product immediately.** Set `APP_NAME` / `config('app.name')` to the real app name. Replace every visitor-facing “Laravel …” / starter phrase (Welcome copy, titles, nav, auth screens) with that name. `live_probe` and `publication_check_front` fail while the live UI still contains `Laravel + Vue + Inertia starter` or `Replace this page with your product.`
4. Edit with `code_*` tools. Prefer Form Requests, policies/`#[Authorize]`, and Inertia pages.
5. Preview: `app_up` with the hosted app id. Migrate via entrypoint using `MIGRATION_DATABASE_URL`. `config/database.php` already reads `DATABASE_URL` (decoded password, `sslmode`, `app` schema) — do not hand-parse it.
6. Verify: commands in `untasked.json`. Browser journeys use Playwright with system Chrome.

Do not use localStorage as the database. Do not rely on `release_command`. Do not run `composer`/`php` on the E2B host. Do not ship the starter Welcome page or Laravel brand copy to fly.dev.

Fly serves the public URL over HTTPS but the container sees HTTP. Keep `$middleware->trustProxies(at: '*')`, `PreferBrandedHost`, root-relative Vite asset paths (`Vite::createAssetPathsUsing`), and `URL::forceScheme('https')` when `APP_URL` is https (already in `bootstrap/app.php`). Do **not** call `URL::forceRootUrl`, set `ASSET_URL`, or restrict accepted hosts with `trustHosts` / `TrustHosts` / `UNTASKED_ALLOWED_HOSTS` — the app is served from branded `*.untasked.run`, fly.dev, candidate, and custom hosts, and the owner may download the code and run it elsewhere. Asset and Ziggy/Inertia URLs must follow the visitor host (`X-Untasked-Original-Host` from the branded router). Without trustProxies + forceScheme, Inertia/Axios posts go to `http://…` and browsers block them as mixed content. Never hardcode a host in the frontend bundle. Never pin an allowlist of hosts.
