<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_world_leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('range', 16);
            $table->foreignUuid('viewer_uuid')
                ->constrained('pixel_world_players', 'uuid')
                ->restrictOnDelete();
            $table->unsignedInteger('total');
            $table->unsignedInteger('entries_count')->default(0);
            $table->unsignedInteger('pages_total');
            $table->unsignedInteger('pages_collected')->default(0);
            $table->string('status', 16);
            $table->text('failure_reason')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['range', 'status', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_leaderboard_snapshots');
    }
};
