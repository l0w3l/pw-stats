<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_notifications', function (Blueprint $table): void {
            $table->dateTime('next_send_at')->nullable()->after('enabled')
                ->comment('UTC timestamp of the next scheduled occurrence.');
            $table->index(['enabled', 'next_send_at'], 'telegram_notifications_due_index');
        });

        $this->backfillNextSendAt();

        Schema::create('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_notification_id')
                ->constrained('telegram_notifications')
                ->cascadeOnDelete();
            $table->dateTime('scheduled_for')->comment('UTC timestamp identifying this daily occurrence.');
            $table->string('status', 16)->default('pending');
            $table->dateTime('next_attempt_at')->comment('UTC timestamp at which this delivery may next be attempted.');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('claim_expires_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['telegram_notification_id', 'scheduled_for'],
                'telegram_notification_deliveries_occurrence_unique',
            );
            $table->index('next_attempt_at', 'telegram_notification_deliveries_next_attempt_index');
            $table->index(['status', 'claim_expires_at'], 'telegram_notification_deliveries_stale_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_notification_deliveries');

        Schema::table('telegram_notifications', function (Blueprint $table): void {
            $table->dropIndex('telegram_notifications_due_index');
            $table->dropColumn('next_send_at');
        });
    }

    private function backfillNextSendAt(): void
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $startOfToday = $now->startOfDay();

        DB::table('telegram_notifications')
            ->where('enabled', true)
            ->orderBy('id')
            ->chunkById(500, function ($subscriptions) use ($now, $startOfToday): void {
                foreach ($subscriptions as $subscription) {
                    $scheduled = CarbonImmutable::parse(
                        $now->toDateString().' '.(string) $subscription->send_time,
                        'UTC',
                    );
                    $lastSentAt = $subscription->last_sent_at === null
                        ? null
                        : CarbonImmutable::parse((string) $subscription->last_sent_at, 'UTC');

                    // A successful send (or an enable after send_time under the old
                    // behavior) suppresses today's occurrence. Unsent occurrences
                    // remain due, including ones whose wall-clock time has elapsed.
                    if ($lastSentAt?->greaterThanOrEqualTo($startOfToday)) {
                        $scheduled = $scheduled->addDay();
                    }

                    DB::table('telegram_notifications')
                        ->where('id', $subscription->id)
                        ->update(['next_send_at' => $scheduled]);
                }
            });
    }
};
