<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('daily_time_reviews', 'assigned_overtime_seconds')) {
                $table->integer('assigned_overtime_seconds')->default(0)->after('expected_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('daily_time_reviews', 'assigned_overtime_seconds')) {
                $table->dropColumn('assigned_overtime_seconds');
            }
        });
    }
};
