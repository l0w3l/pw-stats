# Telegram notification delivery guarantees

Each scheduled occurrence has a unique, durable delivery-ledger row. A successful
Telegram response is committed in one database transaction with the subscription's
`last_sent_at` and `next_send_at` update. Replaying a completed ledger occurrence
does not advance the subscription a second time.

The Telegram API call and the database transaction cannot be atomic. If Telegram
accepts a message and the subsequent database commit fails or has an ambiguous
result, the worker logs only the stable delivery ID and leaves the claim in place
until its TTL. A later stale-claim recovery may send that occurrence again. The
system therefore provides **at-least-once**, not exactly-once, delivery across this
failure boundary. Retry exhaustion is terminal for that occurrence and is retained
in the ledger as `exhausted`.

Telegram `429 retry_after` responses are persisted as the ledger's
`next_attempt_at`; workers do not sleep for that delay. The same deadline is also
published to the shared global/chat limiter when its cache is available. Other
transient failures use configured capped backoff. Permanent Telegram 4xx responses
are terminal and, by default, disable the subscription to avoid recurring retries.
