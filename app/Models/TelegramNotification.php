<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring Telegram notification subscription. All times are UTC.
 *
 * @property int $id
 * @property int $instance_id
 * @property int|null $thread_id
 * @property string $context_key
 * @property string $locale
 * @property string $frequency
 * @property string $send_time
 * @property bool $enabled
 * @property CarbonImmutable|null $next_send_at
 * @property CarbonImmutable|null $last_sent_at
 */
class TelegramNotification extends Model
{
    public const FREQUENCY_DAY = 'day';

    public const FREQUENCY_WEEK = 'week';

    public const FREQUENCY_MONTH = 'month';

    /** @var list<string> */
    public const FREQUENCIES = [
        self::FREQUENCY_DAY,
        self::FREQUENCY_WEEK,
        self::FREQUENCY_MONTH,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'ru',
        'frequency' => self::FREQUENCY_DAY,
        'send_time' => '00:00:00',
        'enabled' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'instance_id',
        'thread_id',
        'locale',
        'frequency',
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

    public static function nextOccurrence(
        string $sendTime,
        CarbonImmutable $now,
        string $frequency = self::FREQUENCY_DAY,
    ): CarbonImmutable {
        $now = $now->utc();
        $occurrence = CarbonImmutable::parse($now->toDateString().' '.$sendTime, 'UTC');

        if ($occurrence->greaterThan($now)) {
            return $occurrence;
        }

        return match ($frequency) {
            self::FREQUENCY_WEEK => $occurrence->addWeek(),
            self::FREQUENCY_MONTH => $occurrence->addMonthNoOverflow(),
            default => $occurrence->addDay(),
        };
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
