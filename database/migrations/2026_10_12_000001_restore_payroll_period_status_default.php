<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('status', 50)->nullable()->default('borrador')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('status', 50)->nullable()->default(null)->change();
        });
    }
};
