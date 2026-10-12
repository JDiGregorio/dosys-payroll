<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            $table->boolean('off_worked')->default(false);
            $table->unsignedInteger('off_worked_verified_seconds')->nullable();
        });
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->decimal('off_worked_extra_amount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', fn (Blueprint $table) => $table->dropColumn('off_worked_extra_amount'));
        Schema::table('daily_time_reviews', fn (Blueprint $table) => $table->dropColumn(['off_worked', 'off_worked_verified_seconds']));
    }
};
