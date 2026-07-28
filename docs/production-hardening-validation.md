# Production hardening validation

Validated on 2026-07-21.

| Area | Result |
| --- | --- |
| Laravel tests | 124 tests, 587 assertions; 3 environment-dependent skips in the normal SQLite run |
| PHP formatting | Pint passed |
| Composer | `validate --strict` passed; no security advisories |
| Frontend | Vite production build passed; npm audit found no vulnerabilities |
| SQLite | Full `migrate:fresh` passed through migration `000009` |
| Sidecar | Isolated pytest cases and Ruff pass in the Docker test target |
| Docker | Development and production Compose rendering passed; shell syntax checks passed |
| PostgreSQL | Notification `SKIP LOCKED` claim test and concurrent first-period writer test passed against PostgreSQL 18 |
| Redis | Shared lock and cross-instance cooldown test passed against Redis 7 |

## Report traceability

- Credential logs: removed and covered by sidecar log-redaction tests.
- Sidecar access: private network isolation, explicit bot allowlist, one operation, and concurrency/rate bounds.
- Telegram init-data mutation: exact payload preservation tests cover ordering, duplicates, reserved and extra signed fields.
- Group settings authorization: private/admin/member/channel/topic and inbound-throttle tests.
- Guzzle advisories and redirects: Guzzle 7.15.1, redirects disabled, Composer audit clean.
- Full entry rewrites: transactional changed-row delta persistence with rank-swap and 1,000-row write-bound tests.
- PHP momentum join/top sort: bounded indexed SQL queries and tie/top-five tests.
- Notification scans and serial backlog: indexed `next_send_at`, durable ledger, bounded queue claims, stale recovery and PostgreSQL concurrency tests.
- Notification throttling: persisted retry scheduling and Redis-backed `retry_after` cooldown test.
- Minute/history growth: hourly rollups and bounded detailed-entry retention with analytics-preservation tests.
- Chart churn: visual-content cache identity tests.
- Docker startup and ingress: health-gated setup, PostgreSQL defaults, Vite service, webhook body/rate/connection limits, private networks, non-root sidecar, constrained Nginx master.

The normal test suite intentionally skips external PostgreSQL and Redis cases. The validation commands run those cases separately against disposable PostgreSQL and the Docker Redis service.
