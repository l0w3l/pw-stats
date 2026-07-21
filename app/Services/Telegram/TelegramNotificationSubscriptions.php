<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramContext;
use App\Models\TelegramNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TelegramNotificationSubscriptions
{
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

    public function enable(TelegramContext $context, ?CarbonImmutable $now = null): TelegramNotification
    {
        $subscription = $this->findOrCreate($context);

        if (! $subscription->enabled) {
            $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
            $subscription->enabled = true;

            // Enabling after today's scheduled UTC time starts with the next occurrence.
            if ($now->format('H:i:s') >= $subscription->send_time) {
                $subscription->last_sent_at = $now;
            }

            $subscription->save();
        }

        return $subscription->refresh();
    }

    public function toggle(TelegramContext $context, ?CarbonImmutable $now = null): TelegramNotification
    {
        $subscription = $this->findOrCreate($context);

        if ($subscription->enabled) {
            $subscription->update(['enabled' => false]);

            return $subscription->refresh();
        }

        return $this->enable($context, $now);
    }

    public function toggleSubscription(
        TelegramNotification $subscription,
        ?CarbonImmutable $now = null,
    ): TelegramNotification {
        if ($subscription->enabled) {
            $subscription->update(['enabled' => false]);

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
}
