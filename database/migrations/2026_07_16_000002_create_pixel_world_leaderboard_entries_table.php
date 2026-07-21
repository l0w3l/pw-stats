<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_world_leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('pixel_world_leaderboard_snapshots')
                ->cascadeOnDelete();
            $table->foreignUuid('player_uuid')
                ->constrained('pixel_world_players', 'uuid')
                ->restrictOnDelete();
            $table->unsignedInteger('place');
            $table->unsignedBigInteger('points');
            $table->string('nickname');
            $table->unsignedInteger('level');
            $table->boolean('has_premium');
            $table->string('image_url')->nullable();
            $table->string('mask_image_url')->nullable();

            $table->unique(['snapshot_id', 'player_uuid']);
            $table->unique(['snapshot_id', 'place']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_leaderboard_entries');
    }
};
