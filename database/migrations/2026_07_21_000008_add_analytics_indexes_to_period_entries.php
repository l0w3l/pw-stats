<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pixel_world_leaderboard_period_entries', function (Blueprint $table): void {
            $table->index(
                ['period_id', 'points'],
                'pw_period_entries_period_points_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pixel_world_leaderboard_period_entries', function (Blueprint $table): void {
            $table->dropIndex('pw_period_entries_period_points_index');
        });
    }
};
