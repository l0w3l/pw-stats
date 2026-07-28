# Repository Guide

## Commands

- Run first-time local setup with `composer setup`; it creates `.env`, generates `APP_KEY`, runs migrations, installs Node dependencies, and builds Vite assets.
- Run the local development stack with `composer dev`; it starts the Laravel server, queue listener, Pail, and Vite together.
- Run the full suite with `composer test`. It clears Laravel's configuration cache before invoking Pest. Run a focused test with `php artisan test tests/Feature/ExampleTest.php` or `composer test -- --filter='test name'`.
- Format PHP with `vendor/bin/pint`; use `vendor/bin/pint --test` for a check-only pass. Build frontend assets with `npm run build` or watch them with `npm run dev`.
- Update leaderboard periods manually with `php artisan pixel-world:leaderboards:collect`; inspect the latest successful ones with `php artisan pixel-world:leaderboards:show --range=day`.

## Application Boundaries

- This is a Laravel 13 / PHP 8.3 application. `bootstrap/app.php` registers web/console routes; the Telepath service provider separately loads the path configured by `telepath.routes` (default: `routes/telegram.php`).
- Feature tests use Pest with `Tests\TestCase`; PHPUnit forces an in-memory SQLite connection. Database-dependent tests must opt into database reset behavior (for example, `RefreshDatabase`), which is currently commented out in `tests/Pest.php`.
- `TelegramWebAppDataClient` talks to the private `access-token` FastAPI sidecar and caches exact Telegram `web_app_data`; `PixelWorldTokenProvider` exchanges it for a cached Bearer token. `PixelWorldLeaderboardClient` owns authenticated leaderboard HTTP, retries, and rate limiting.
- Minute-level `PlayersLeaderboardListData::total` samples are stored separately in `pixel_world_player_totals` for DAY/WEEK/MONTH. `pixel-world:player-totals:collect` runs every minute, uses page 1 with limit 1, and is idempotent per UTC minute. Bearer tokens use an in-process memory value plus the cache store configured by `PIXEL_WORLD_TOKEN_CACHE_STORE` (use Redis in multi-process production).
- Full leaderboard scans are staggered by default (DAY every 30 minutes, WEEK hourly at `:17`, MONTH every two hours at `:37`) because all ranges share the upstream request quota. Override the `PIXEL_WORLD_*_COLLECTION_CRON` values only from measured collection duration and queue lag.
- Retention runs in UTC with single-server and bounded overlap guards: player-total rollup/prune runs hourly at `:07` with a 30-minute lock, and leaderboard-detail prune runs daily at `02:37` with a 60-minute lock. Both commands expose candidate/affected counts and support `--dry-run` without mutation.
- Leaderboards use one mutable `pixel_world_leaderboard_periods` row per range/calendar period (DAY date, ISO Monday–Sunday WEEK, calendar MONTH), retaining completed past periods. Collection and reconciliation happen outside database transactions; `LeaderboardPeriodWriter` atomically replaces only a successfully collected period, so failures preserve prior data. Later, better-ranked pages win UUID/place conflicts. Periods can be partial; check `is_partial`/`missing_places` when strict completeness matters.
- Migration `2026_07_20_000004_replace_dirty_leaderboard_snapshots_with_periods` keeps only the latest completed legacy snapshot per calendar period, copies selected entries database-side, then drops the multi-gigabyte legacy tables. It is intentionally non-transactional and requires temporary free disk for old plus selected data.
- Pixel World leaderboard `points` represent kills; analytics intentionally expose them as kills and derive WEEK/7 and MONTH/30 averages.
- Telegram notification `send_time` is a recurring UTC wall-clock `TIME`, not a one-shot datetime. Users select a `day`, `week`, or `month` frequency in `/start`; changing frequency preserves `send_time`, and completed occurrences advance by that cadence. Delivery runs minutely, records `last_sent_at` only after Bot API success, and suppresses same-day catch-up when a disabled subscription is enabled after its scheduled time.
- Telegram subscriptions are scoped by signed `instance_id` (chat/channel) plus nullable `thread_id` (forum topic). Production notification pacing requires `TELEGRAM_NOTIFICATION_CACHE_STORE` to be a shared lock-capable cache.
- Telegram delivery is at-least-once across the Bot API/database boundary; an ambiguous post-send database failure can duplicate an occurrence after claim recovery. See `docs/telegram-notification-delivery.md`.

## Docker Runtime

- Use `./docker/dc` rather than invoking Compose directly. It passes `docker/.env` and the application `.env` to Compose without sourcing either as shell, derives a deterministic project name, and exports the host UID/GID so bind-mounted files retain host ownership. `./docker/dc dev up` includes Vite; `./docker/dc prod up` builds static assets during setup.
- For a fresh Docker installation, copy both `.env.example` to `.env` and `docker/.env.example` to `docker/.env`. Docker overrides the application database host with PostgreSQL (`postgres`) and Redis host with `redis`; keep the PostgreSQL credentials in both files aligned. Generate `APP_KEY`, and never use the example credentials in production.
- Set the Telegram MTProto API ID/hash and optional phone/2FA in `docker/.env`. The sidecar session name must remain `session_data/session_name`; the named `telegram_session` volume then persists `/app/session_data/session_name.session`. Authenticate it from `docker/` with `./telegram_auth.sh` before normal startup. The helper repairs legacy volume ownership before running Telethon as the non-root application user.
- Existing `telegram_session` volumes created by the former root container may need a one-time ownership migration before enabling the non-root image: `./docker/dc run --rm --user 0 --cap-add CHOWN access-token chown -R 10001:10001 /app/session_data`.
- PostgreSQL and Redis healthchecks gate setup. FPM, queue, scheduler, and development Vite start only after setup exits successfully, so a failed migration/build blocks the application rather than accepting a stale marker. Inspect with `./docker/dc logs setup` and rerun `./docker/dc up` after fixing the cause.
- The Docker `setup` service runs migrations and caches config, routes, and views. Clear these caches with `./docker/dc artisan optimize:clear` when validating configuration or route changes in that runtime. `./docker/dc composer ...` targets FPM; `./docker/dc dev npm ...` targets the running Vite service (production npm commands target FPM).
