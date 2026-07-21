# Leaderboard history retention

The application retains every `pixel_world_leaderboard_periods` row indefinitely. Period totals,
collection timestamps, completeness metadata, and player counts therefore remain available to
trend and chart analytics after detailed player entries are pruned.

Detailed entries are retained for the newest 30 DAY, 12 WEEK, and 12 MONTH periods by default.
Configure those windows with `PIXEL_WORLD_LEADERBOARD_DAY_DETAILED_PERIODS`,
`PIXEL_WORLD_LEADERBOARD_WEEK_DETAILED_PERIODS`, and
`PIXEL_WORLD_LEADERBOARD_MONTH_DETAILED_PERIODS`. Regardless of configuration, the newest two
periods in every range are always protected. This hard minimum preserves DAY momentum comparisons
and the current/previous trend periods; the newest WEEK and MONTH details used for top-player
analytics are protected as part of the same rule.

Run `php artisan pixel-world:leaderboards:prune --dry-run` to report eligible period and entry
counts without changing data. Without `--dry-run`, one invocation deletes at most
`PIXEL_WORLD_LEADERBOARD_ENTRY_RETENTION_CHUNK_SIZE` entries (5,000 by default). Selection uses
the period and entry indexes, and retries are idempotent. Invoke it repeatedly to drain a backlog.
The command is scheduled daily at 02:37 UTC, away from ten-minute leaderboard collection starts,
on one scheduler server with a bounded 60-minute overlap lock. Command output reports candidate,
selected, and affected entry counts.
