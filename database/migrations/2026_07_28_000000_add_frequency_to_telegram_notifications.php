<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_notifications', function (Blueprint $table): void {
            $table->string('frequency', 5)->default('day')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_notifications', function (Blueprint $table): void {
            $table->dropColumn('frequency');
        });
    }
};
