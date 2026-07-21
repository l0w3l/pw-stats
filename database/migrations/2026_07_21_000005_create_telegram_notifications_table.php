<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_notifications', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('instance_id')->comment('Signed Telegram chat or channel ID.');
            $table->bigInteger('thread_id')->nullable()->comment('Signed Telegram forum topic ID.');
            $table->string('context_key', 64)->unique()->comment('NULL-safe chat and thread identity.');
            $table->time('send_time')->default('00:00:00')->comment('Recurring daily UTC wall-clock time.');
            $table->boolean('enabled')->default(false);
            $table->dateTime('last_sent_at')->nullable()->comment('UTC time of the last successful delivery.');
            $table->timestamps();

            $table->index(['enabled', 'send_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_notifications');
    }
};
