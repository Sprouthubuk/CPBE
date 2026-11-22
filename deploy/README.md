# Deploying on Laravel Forge

One server runs both halves: PHP-FPM serves Laravel, a Node daemon serves Astro, nginx in front
routes by path (`nginx.conf`). Same domain, so Sanctum cookies just work.

## One-off setup

1. **Server**: Forge app server (London, 4 GB), PHP 8.5 (with `gmp`, `intl`, `bcmath`), MySQL 8.4, Node 20+.
   In MySQL set `log_bin_trust_function_creators = 1` (Verity's triggers need it).
2. **Site**: `example.co.uk`, project type "General PHP / Laravel", web directory
   `/backend/public`, zero-downtime deployments on, repo `Sprouthubuk/CPBE`, branch `main`.
3. **Private Verity package**: Forge → Server → Composer credentials (or `auth.json`): a GitHub
   token with read access to `Sprouthubuk/verity-engine`.
4. **Environment**: paste `backend/.env.example` into Forge's environment editor and fill in
   the secrets (Cashflows, Resend, Verity keys). `SESSION_DOMAIN=example.co.uk`,
   `SANCTUM_STATEFUL_DOMAINS=example.co.uk`, `APP_URL=https://example.co.uk`.
5. **Nginx**: edit the site's config with `nginx.conf` — both blocks: the main site and the private
   listener on 127.0.0.1:8080 that Astro uses to reach Laravel.
6. **Background processes**: Astro and the queue worker (`astro-daemon.conf`).
7. **Scheduler**: Forge → Scheduler → `php artisan schedule:run` every minute (expires unpaid
   orders, Verity schedules, bonus expiry, health email).
8. **SSL**: Let's Encrypt.
9. **Deploy script**: `forge-deploy.sh`, with "Make .env variables available to deployment script" on.

## Fresh secrets and settings for Brand Name (never reuse another site's)

Liam adds every value in Forge himself; none goes in chat, a commit or a ticket.

| What | Where | How |
| --- | --- | --- |
| `APP_KEY` | Forge → Environment | `php artisan key:generate --show` on the server |
| Database | Forge → Database | a new MySQL database and user (`DB_DATABASE=platform`, own password) |
| `VERITY_SEED_KEY` | Forge → Environment | `php artisan verity:seal-seeds --new-key`, before the first competition; keep an offline copy, never change it |
| `VERITY_OWNER_PEPPER` | Forge → Environment | `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; offline copy, never change it |
| `RESEND_API_KEY` | Resend | add and verify the `example.co.uk` domain (DNS records), then a key for it |
| `MAIL_FROM_ADDRESS`, `CONTACT_INBOX` | Forge → Environment | the support address, once confirmed |
| `CASHFLOWS_CONFIGURATION_ID`, `CASHFLOWS_API_KEY` | Cashflows | Brand Name's own merchant configuration (integration first, `CASHFLOWS_ENV=integration`) |
| `META_CAPI_TOKEN` | Meta Events Manager | a token for Brand Name's own dataset; the pixel id goes in Admin → Settings |
| Composer credentials | Forge → Server | a GitHub token that can read `Sprouthubuk/verity-engine` |
| `FRONTEND_URL` | Forge → Environment | the live address; the deploy script builds the site with it as `PUBLIC_SITE_URL` |

## Checks after a deploy

- `https://example.co.uk/` loads; `/api/site` returns JSON; `/admin` shows Filament login.
- Sign in as a test player, buy one ticket from the wallet, play it.
- Cashflows sandbox: a card payment returns to `/order/CP-…` and confirms; a cancelled one puts
  the basket back.
