<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_world_players', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('image_url')->nullable();
            $table->string('mask_image_url')->nullable();
            $table->unsignedInteger('level');
            $table->string('nickname');
            $table->boolean('has_premium');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_players');
    }
};
