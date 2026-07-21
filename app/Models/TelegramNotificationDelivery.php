<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable state for one scheduled notification occurrence.
 *
 * @property int $id
 * @property int $telegram_notification_id
 * @property CarbonImmutable $scheduled_for
 * @property string $status
 * @property CarbonImmutable $next_attempt_at
 * @property int $attempt_count
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $claim_expires_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error
 */
class TelegramNotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PERMANENT = 'permanent';

    public const STATUS_EXHAUSTED = 'exhausted';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempt_count' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'telegram_notification_id',
        'scheduled_for',
        'status',
        'next_attempt_at',
        'attempt_count',
        'claim_token',
        'claimed_at',
        'claim_expires_at',
        'sent_at',
        'failed_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'claim_expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TelegramNotification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(TelegramNotification::class, 'telegram_notification_id');
    }
}
