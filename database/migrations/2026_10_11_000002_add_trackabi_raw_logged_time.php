<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->string('raw_logged_time')->nullable();
        });

        Schema::table('tracking_import_alerts', function (Blueprint $table): void {
            $table->string('external_id')->nullable();
            $table->unique(['payroll_period_id', 'code', 'external_id'], 'tracking_alert_period_code_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_import_alerts', function (Blueprint $table): void {
            $table->dropUnique('tracking_alert_period_code_external_unique');
            $table->dropColumn('external_id');
        });

        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->dropColumn('raw_logged_time');
        });
    }
};
