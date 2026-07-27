<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramContext;
use App\Models\TelegramNotification;
use App\Models\TelegramNotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramNotificationSubscriptions
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['ru', 'en'];

    public function findOrCreate(TelegramContext $context): TelegramNotification
    {
        return TelegramNotification::query()->firstOrCreate(
            ['context_key' => TelegramNotification::contextKey($context->instanceId, $context->threadId)],
            ['instance_id' => $context->instanceId, 'thread_id' => $context->threadId],
        );
    }

    public function findForCallback(int $id, TelegramContext $context): TelegramNotification
    {
        return TelegramNotification::query()
            ->whereKey($id)
            ->where('instance_id', $context->instanceId)
            ->when(
                $context->hasThreadContext,
                fn ($query) => $query->where('thread_id', $context->threadId),
            )
            ->firstOr(function (): never {
                throw (new ModelNotFoundException)->setModel(TelegramNotification::class);
            });
    }

    public function updateLocale(TelegramNotification $subscription, string $locale): TelegramNotification
    {
        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new InvalidArgumentException('Unsupported Telegram subscription locale.');
        }

        $subscription->update(['locale' => $locale]);

        return $subscription->refresh();
    }

    public function enable(TelegramContext $context, ?CarbonImmutable $now = null): TelegramNotification
    {
        $subscription = $this->findOrCreate($context);

        if (! $subscription->enabled) {
            $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
            $subscription->enabled = true;
            $subscription->next_send_at = TelegramNotification::nextOccurrence(
                $subscription->send_time,
                $now,
            );

            $subscription->save();
        }

        return $subscription->refresh();
    }

    public function toggle(TelegramContext $context, ?CarbonImmutable $now = null): TelegramNotification
    {
        $subscription = $this->findOrCreate($context);

        if ($subscription->enabled) {
            $subscription->update(['enabled' => false, 'next_send_at' => null]);

            return $subscription->refresh();
        }

        return $this->enable($context, $now);
    }

    public function toggleSubscription(
        TelegramNotification $subscription,
        ?CarbonImmutable $now = null,
    ): TelegramNotification {
        if ($subscription->enabled) {
            $subscription->update(['enabled' => false, 'next_send_at' => null]);

            return $subscription->refresh();
        }

        return $this->enable(
            new TelegramContext($subscription->instance_id, $subscription->thread_id),
            $now,
        );
    }

    /** @return Collection<int, TelegramNotification> */
    public function due(CarbonImmutable $now): Collection
    {
        return TelegramNotification::query()
            ->dueAt($now->utc())
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, TelegramNotificationDelivery> */
    public function claimDue(CarbonImmutable $now, int $limit, int $claimTtlSeconds): Collection
    {
        $now = $now->utc();
        $limit = max(1, $limit);
        $claimExpiresAt = $now->addSeconds(max(1, $claimTtlSeconds));
        $claimToken = (string) Str::uuid();
        $maxAttempts = max(1, (int) config('telegram_notifications.delivery.max_attempts', 5));

        return DB::transaction(function () use ($now, $limit, $claimExpiresAt, $claimToken, $maxAttempts): Collection {
            $claimedIds = [];
            $exhausted = TelegramNotificationDelivery::query()
                ->where('attempt_count', '>=', $maxAttempts)
                ->where(function (Builder $query) use ($now): void {
                    $query->where(function (Builder $query) use ($now): void {
                        $query->whereIn('status', [
                            TelegramNotificationDelivery::STATUS_PENDING,
                            TelegramNotificationDelivery::STATUS_FAILED,
                        ])->where('next_attempt_at', '<=', $now);
                    })->orWhere(function (Builder $query) use ($now): void {
                        $query->whereIn('status', [
                            TelegramNotificationDelivery::STATUS_CLAIMED,
                            TelegramNotificationDelivery::STATUS_PROCESSING,
                        ])
                            ->where('claim_expires_at', '<=', $now);
                    });
                })
                ->orderBy('id')
                ->limit($limit);
            $this->lockClaimQuery($exhausted);

            foreach ($exhausted->get() as $delivery) {
                $this->finalizeTerminal($delivery, TelegramNotificationDelivery::STATUS_EXHAUSTED, $now, false);
            }

            $recoverable = TelegramNotificationDelivery::query()
                ->whereHas('notification', fn (Builder $query) => $query
                    ->where('enabled', true)
                    ->whereColumn(
                        'telegram_notifications.next_send_at',
                        'telegram_notification_deliveries.scheduled_for',
                    ))
                ->where('attempt_count', '<', $maxAttempts)
                ->where(function (Builder $query) use ($now): void {
                    $query->where(function (Builder $query) use ($now): void {
                        $query->whereIn('status', [
                            TelegramNotificationDelivery::STATUS_PENDING,
                            TelegramNotificationDelivery::STATUS_FAILED,
                        ])->where('next_attempt_at', '<=', $now);
                    })->orWhere(function (Builder $query) use ($now): void {
                        $query->whereIn('status', [
                            TelegramNotificationDelivery::STATUS_CLAIMED,
                            TelegramNotificationDelivery::STATUS_PROCESSING,
                        ])
                            ->where('claim_expires_at', '<=', $now);
                    });
                })
                ->orderBy('scheduled_for')
                ->orderBy('id')
                ->limit($limit);
            $this->lockClaimQuery($recoverable);
            $recoverableDeliveries = $recoverable->get();

            foreach ($recoverableDeliveries as $delivery) {
                if ($this->claim($delivery->id, $claimToken, $now, $claimExpiresAt, $maxAttempts)) {
                    $claimedIds[] = $delivery->id;
                }
            }

            // Bound selected work as well as successful claims. A raced
            // conditional update may intentionally leave this batch short.
            $remaining = $limit - $recoverableDeliveries->count();
            if ($remaining > 0) {
                $due = TelegramNotification::query()
                    ->dueAt($now)
                    ->whereDoesntHave('deliveries', function (Builder $query) use ($now): void {
                        $query->whereColumn(
                            'telegram_notification_deliveries.scheduled_for',
                            'telegram_notifications.next_send_at',
                        )->where(function (Builder $query) use ($now): void {
                            $query->where('status', TelegramNotificationDelivery::STATUS_SENT)
                                ->orWhere(function (Builder $query) use ($now): void {
                                    $query->whereIn('status', [
                                        TelegramNotificationDelivery::STATUS_CLAIMED,
                                        TelegramNotificationDelivery::STATUS_PROCESSING,
                                    ])
                                        ->where('claim_expires_at', '>', $now);
                                });
                        });
                    })
                    ->orderBy('id')
                    ->limit($remaining);
                $this->lockClaimQuery($due);

                foreach ($due->get() as $notification) {
                    $scheduledFor = $notification->next_send_at;
                    if ($scheduledFor === null) {
                        continue;
                    }

                    $delivery = TelegramNotificationDelivery::query()->firstOrCreate(
                        ['telegram_notification_id' => $notification->id, 'scheduled_for' => $scheduledFor],
                        ['next_attempt_at' => $scheduledFor],
                    );

                    if ($this->claim($delivery->id, $claimToken, $now, $claimExpiresAt, $maxAttempts)) {
                        $claimedIds[] = $delivery->id;
                    }
                }
            }

            return TelegramNotificationDelivery::query()
                ->whereIn('id', $claimedIds)
                ->where('claim_token', $claimToken)
                ->orderBy('id')
                ->get();
        }, 3);
    }

    /**
     * @param  list<int>  $deliveryIds
     * @return Collection<int, TelegramNotificationDelivery>
     */
    public function renewClaims(array $deliveryIds, string $claimToken, CarbonImmutable $now, int $claimTtlSeconds): Collection
    {
        $expiresAt = $now->utc()->addSeconds(max(1, $claimTtlSeconds));
        $maxAttempts = max(1, (int) config('telegram_notifications.delivery.max_attempts', 5));

        return DB::transaction(function () use (
            $deliveryIds,
            $claimToken,
            $now,
            $expiresAt,
            $maxAttempts,
        ): Collection {
            $eligible = TelegramNotificationDelivery::query()
                ->whereKey($deliveryIds)
                ->where('status', TelegramNotificationDelivery::STATUS_CLAIMED)
                ->where('claim_token', $claimToken)
                ->whereNull('sent_at')
                ->where('attempt_count', '<', $maxAttempts)
                ->lockForUpdate()
                ->pluck('id');

            if ($eligible->isEmpty()) {
                return new Collection;
            }

            TelegramNotificationDelivery::query()
                ->whereKey($eligible->all())
                ->update([
                    'status' => TelegramNotificationDelivery::STATUS_PROCESSING,
                    'claim_expires_at' => $expiresAt,
                    // Queue wait and stale re-claims are not delivery attempts. The
                    // budget starts only when a worker begins processing the claim.
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);

            return TelegramNotificationDelivery::query()
                ->with('notification')
                ->whereKey($eligible->all())
                ->where('status', TelegramNotificationDelivery::STATUS_PROCESSING)
                ->where('claim_token', $claimToken)
                ->where('claim_expires_at', '>', $now->utc())
                ->orderBy('id')
                ->get();
        }, 3);
    }

    public function complete(int $deliveryId, string $claimToken, CarbonImmutable $sentAt): bool
    {
        $sentAt = $sentAt->utc();

        return DB::transaction(function () use ($deliveryId, $claimToken, $sentAt): bool {
            $delivery = TelegramNotificationDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || $delivery->status !== TelegramNotificationDelivery::STATUS_PROCESSING
                || $delivery->claim_token !== $claimToken || $delivery->sent_at !== null) {
                return false;
            }

            $notification = TelegramNotification::query()->lockForUpdate()->find($delivery->telegram_notification_id);
            if ($notification === null) {
                return false;
            }

            $delivery->forceFill([
                'status' => TelegramNotificationDelivery::STATUS_SENT,
                'sent_at' => $sentAt,
                'failed_at' => null,
                'last_error' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ])->save();

            $nextSendAt = TelegramNotification::nextOccurrence($notification->send_time, $sentAt);
            $notification->forceFill([
                'last_sent_at' => $notification->last_sent_at?->greaterThan($sentAt) === true
                    ? $notification->last_sent_at
                    : $sentAt,
                'next_send_at' => $notification->next_send_at?->lessThan($nextSendAt) === true
                    ? $nextSendAt
                    : $notification->next_send_at,
            ])->save();

            return true;
        }, 3);
    }

    public function retry(
        int $deliveryId,
        string $claimToken,
        CarbonImmutable $failedAt,
        CarbonImmutable $nextAttemptAt,
        string $reason,
    ): bool {
        return TelegramNotificationDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', TelegramNotificationDelivery::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->whereNull('sent_at')
            ->update([
                'status' => TelegramNotificationDelivery::STATUS_FAILED,
                'next_attempt_at' => $nextAttemptAt->utc(),
                'failed_at' => $failedAt->utc(),
                'last_error' => $reason,
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]) === 1;
    }

    public function terminate(
        int $deliveryId,
        string $claimToken,
        CarbonImmutable $failedAt,
        string $status,
        string $reason,
        bool $disableSubscription,
    ): bool {
        return DB::transaction(function () use (
            $deliveryId,
            $claimToken,
            $failedAt,
            $status,
            $reason,
            $disableSubscription,
        ): bool {
            $delivery = TelegramNotificationDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || $delivery->status !== TelegramNotificationDelivery::STATUS_PROCESSING
                || $delivery->claim_token !== $claimToken || $delivery->sent_at !== null) {
                return false;
            }

            $this->finalizeTerminal($delivery, $status, $failedAt->utc(), $disableSubscription, $reason);

            return true;
        }, 3);
    }

    public function release(int $deliveryId, string $claimToken, CarbonImmutable $nextAttemptAt): void
    {
        TelegramNotificationDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', TelegramNotificationDelivery::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->whereNull('sent_at')
            ->update([
                'status' => TelegramNotificationDelivery::STATUS_PENDING,
                'next_attempt_at' => $nextAttemptAt->utc(),
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);
    }

    /** @param Builder<TelegramNotification|TelegramNotificationDelivery> $query */
    private function lockClaimQuery(Builder $query): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->lock('for update skip locked');
        } else {
            $query->lockForUpdate();
        }
    }

    private function claim(
        int $deliveryId,
        string $claimToken,
        CarbonImmutable $now,
        CarbonImmutable $claimExpiresAt,
        int $maxAttempts,
    ): bool {
        return TelegramNotificationDelivery::query()
            ->whereKey($deliveryId)
            ->whereNull('sent_at')
            ->where('attempt_count', '<', $maxAttempts)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query->whereIn('status', [
                        TelegramNotificationDelivery::STATUS_PENDING,
                        TelegramNotificationDelivery::STATUS_FAILED,
                    ])->where('next_attempt_at', '<=', $now);
                })->orWhere(function (Builder $query) use ($now): void {
                    $query->whereIn('status', [
                        TelegramNotificationDelivery::STATUS_CLAIMED,
                        TelegramNotificationDelivery::STATUS_PROCESSING,
                    ])
                        ->where('claim_expires_at', '<=', $now);
                });
            })
            ->update([
                'status' => TelegramNotificationDelivery::STATUS_CLAIMED,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                'claim_expires_at' => $claimExpiresAt,
            ]) === 1;
    }

    private function finalizeTerminal(
        TelegramNotificationDelivery $delivery,
        string $status,
        CarbonImmutable $failedAt,
        bool $disableSubscription,
        string $reason = 'attempts_exhausted',
    ): void {
        $notification = TelegramNotification::query()
            ->lockForUpdate()
            ->find($delivery->telegram_notification_id);

        $delivery->forceFill([
            'status' => $status,
            'failed_at' => $failedAt,
            'last_error' => $reason,
            'claim_token' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
        ])->save();

        if ($notification === null) {
            return;
        }

        $nextOccurrence = TelegramNotification::nextOccurrence($notification->send_time, $failedAt);
        $notification->forceFill($disableSubscription ? [
            'enabled' => false,
            'next_send_at' => null,
        ] : [
            // This occurrence is terminal. Move to the next daily occurrence
            // so it is not reconsidered by every scheduler tick.
            'next_send_at' => $notification->next_send_at?->greaterThan($nextOccurrence) === true
                ? $notification->next_send_at
                : $nextOccurrence,
        ])->save();
    }
}
