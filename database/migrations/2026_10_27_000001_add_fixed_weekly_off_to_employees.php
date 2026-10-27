<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedTinyInteger('fixed_weekly_off_day')->nullable();
            $table->date('fixed_weekly_off_from_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['fixed_weekly_off_day', 'fixed_weekly_off_from_date']);
        });
    }
};
