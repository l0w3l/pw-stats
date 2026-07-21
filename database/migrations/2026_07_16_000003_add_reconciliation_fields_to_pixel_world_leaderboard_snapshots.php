<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pixel_world_leaderboard_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('missing_places')->default(0)->after('entries_count');
            $table->boolean('is_partial')->default(false)->after('missing_places');
        });
    }

    public function down(): void
    {
        Schema::table('pixel_world_leaderboard_snapshots', function (Blueprint $table) {
            $table->dropColumn(['missing_places', 'is_partial']);
        });
    }
};
