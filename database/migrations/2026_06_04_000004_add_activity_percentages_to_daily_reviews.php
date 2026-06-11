<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('daily_time_reviews', 'activity_percentage')) {
                $table->decimal('activity_percentage', 8, 2)->nullable()->after('hubstaff_idle_seconds');
            }

            if (! Schema::hasColumn('daily_time_reviews', 'idle_percentage')) {
                $table->decimal('idle_percentage', 8, 2)->nullable()->after('activity_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('daily_time_reviews', 'idle_percentage')) {
                $table->dropColumn('idle_percentage');
            }

            if (Schema::hasColumn('daily_time_reviews', 'activity_percentage')) {
                $table->dropColumn('activity_percentage');
            }
        });
    }
};
