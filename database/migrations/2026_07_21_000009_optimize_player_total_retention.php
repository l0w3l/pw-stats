<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_world_player_total_hourlies', function (Blueprint $table): void {
            $table->id();
            $table->string('range', 8);
            $table->dateTime('hour_at', 0)->comment('UTC hour represented by this aggregate.');
            $table->integer('sample_count');
            $table->bigInteger('minimum_total');
            $table->bigInteger('maximum_total');
            $table->decimal('average_total', 14, 2);
            $table->bigInteger('first_total');
            $table->bigInteger('last_total');
            $table->timestamps();

            $table->unique(['range', 'hour_at']);
            $table->index('hour_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_player_total_hourlies');
    }
};
