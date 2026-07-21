<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring daily Telegram notification subscription. All times are UTC.
 *
 * @property int $id
 * @property int $instance_id
 * @property int|null $thread_id
 * @property string $context_key
 * @property string $send_time
 * @property bool $enabled
 * @property CarbonImmutable|null $next_send_at
 * @property CarbonImmutable|null $last_sent_at
 */
class TelegramNotification extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'send_time' => '00:00:00',
        'enabled' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'instance_id',
        'thread_id',
        'send_time',
        'enabled',
        'next_send_at',
        'last_sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'instance_id' => 'integer',
            'thread_id' => 'integer',
            'enabled' => 'boolean',
            'next_send_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $notification): void {
            $notification->context_key = self::contextKey(
                $notification->instance_id,
                $notification->thread_id,
            );
        });
    }

    public static function contextKey(int $instanceId, ?int $threadId): string
    {
        return $instanceId.':'.($threadId === null ? 'root' : $threadId);
    }

    public static function nextOccurrence(string $sendTime, CarbonImmutable $now): CarbonImmutable
    {
        $now = $now->utc();
        $occurrence = CarbonImmutable::parse($now->toDateString().' '.$sendTime, 'UTC');

        return $occurrence->lessThanOrEqualTo($now) ? $occurrence->addDay() : $occurrence;
    }

    /** @return HasMany<TelegramNotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramNotificationDelivery::class);
    }

    /** @param Builder<self> $query */
    public function scopeDueAt(Builder $query, \DateTimeInterface $now): void
    {
        $query
            ->where('enabled', true)
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', $now);
    }
}
