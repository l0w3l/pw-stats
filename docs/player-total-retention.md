# Player-total retention

`pixel_world_player_totals` remains the five-second source for existing consumers. The hourly
retention job does not change analytics queries yet. Complete UTC hours older than
`PIXEL_WORLD_PLAYER_TOTAL_RAW_RETENTION_DAYS` (30 by default) are summarized into
`pixel_world_player_total_hourlies` before their raw rows are deleted. Each summary preserves
sample count, minimum, maximum, average, and first/last values.

The scheduled `pixel-world:player-totals:prune` command runs hourly at minute 7 UTC, away from the
ten-minute leaderboard collection peak. It runs on one scheduler server with a bounded 30-minute
overlap lock. It handles at most `PIXEL_WORLD_PLAYER_TOTAL_RETENTION_HOURS_PER_RUN` UTC hours
(24 by default), using one transaction per hour. Its unique `(range, hour_at)` key makes retries
idempotent. Raw deletion is in the same transaction and occurs after the aggregate upsert.
Hourly rows are retained for `PIXEL_WORLD_PLAYER_TOTAL_HOURLY_RETENTION_DAYS` (730 by default),
and their cleanup is also bounded. Hourly retention must be at least raw retention.

Use `php artisan pixel-world:player-totals:prune --dry-run` to report eligible raw and hourly row
counts without writing summaries or deleting rows. Normal output reports candidate and affected
counts for production monitoring.

With three ranges, raw retention is at most `3 × 1,440 × 30 = 129,600` minute rows. Two years of
hourly history is approximately `3 × 24 × 730 = 52,560` rows. After the first 30 days this is a
60:1 row-count reduction compared with minute history; indexes and row width add database-specific
overhead, so production storage should still be measured from PostgreSQL relation sizes.
