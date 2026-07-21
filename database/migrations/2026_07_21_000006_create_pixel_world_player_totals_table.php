<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_world_player_totals', function (Blueprint $table): void {
            $table->id();
            $table->string('range', 8);
            $table->unsignedInteger('total');
            $table->dateTime('collected_at')->comment('UTC minute represented by this sample.');
            $table->timestamps();

            $table->unique(['range', 'collected_at']);
            $table->index('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_player_totals');
    }
};
