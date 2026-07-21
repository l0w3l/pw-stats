<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TelegramNotificationFixtures
{
    /**
     * Insert a large due set without retaining an Eloquent model graph in the
     * test process. This keeps the fixture itself out of memory assertions.
     */
    public static function dueSubscriptions(int $count, CarbonImmutable $scheduledFor): void
    {
        $timestamp = $scheduledFor->toDateTimeString();

        foreach (array_chunk(range(1, $count), 100) as $instanceIds) {
            DB::table('telegram_notifications')->insert(array_map(
                static fn (int $instanceId): array => [
                    'instance_id' => 10_000_000 + $instanceId,
                    'thread_id' => null,
                    'context_key' => (10_000_000 + $instanceId).':root',
                    'send_time' => '12:30:00',
                    'enabled' => true,
                    'next_send_at' => $timestamp,
                    'last_sent_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $instanceIds,
            ));
        }
    }
}
