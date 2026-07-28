# Production hardening

## Required secrets

- Generate `ACCESS_TOKEN_INTERNAL_SECRET` with at least 32 random characters and set the same value in the application `.env` and `docker/.env`.
- Set `PIXEL_WORLD_BOT_USERNAME` to the mini-app bot and keep it in `PIXEL_WORLD_BOT_USERNAME_ALLOWLIST` for the sidecar.
- Store bearer tokens, notification locks, and inbound throttles in Redis in multi-process production.

The access-token sidecar exposes only the allowlisted main WebView operation. It returns the exact once-decoded Telegram init-data string, requires an internal Bearer credential, runs as a non-root user, and does not log credential-bearing URLs or payloads. Laravel separately exchanges that init data for the Pixel World Bearer token and caches both values with bounded lifetimes.

## Docker runtime

Use literal storage paths in `docker/.env`; dotenv values are never evaluated as shell code. The production Compose override forces `APP_ENV=production`, `APP_DEBUG=false`, and `LOG_LEVEL=warning` even when the mounted application `.env` began from the local example.

PostgreSQL, Redis, setup, and the sidecar are health/completion gated. The sidecar is isolated from nginx, PostgreSQL, Redis, and FlareSolverr. Nginx does not trust forwarded client-IP headers by default; deployments behind a reverse proxy must configure only that proxy's exact address and make it replace incoming forwarding headers.

The standard Nginx image retains a root master solely to bind port 80 and drop privileges for workers. Compose restricts it with a read-only root filesystem, bounded tmpfs mounts, `no-new-privileges`, and an explicit minimal capability set.

## Data lifecycle

- Raw minute totals are retained for 30 days by default and rolled up hourly before deletion.
- Hourly totals are retained for 730 days by default.
- Detailed leaderboard entries retain 30 DAY, 12 WEEK, and 12 MONTH periods by default. Period metadata and totals remain available indefinitely.
- Retention jobs use UTC, bounded batches, single-server locks, overlap protection, and dry-run reporting.

## Delivery semantics

Telegram notifications use indexed absolute scheduling, durable occurrence rows, bounded claims, queue jobs, stale-claim recovery, retry backoff, and `retry_after` cooldowns. Queue wait does not consume a delivery attempt; attempts begin only when a worker starts processing a claim.

Delivery remains at-least-once across the external Telegram/database boundary. If Telegram accepts a message and the process fails before the local outcome commits, a later recovery can duplicate that occurrence. The code retains the claim until expiry and logs only the delivery ID for investigation.

## Collection capacity

Full leaderboard scans share the upstream request quota. Defaults stagger collection to DAY every 30 minutes, WEEK hourly at `:17`, and MONTH every two hours at `:37`. Tune `PIXEL_WORLD_*_COLLECTION_CRON` only after measuring page count, collection duration, queue lag, and `last_collected_at` age.
