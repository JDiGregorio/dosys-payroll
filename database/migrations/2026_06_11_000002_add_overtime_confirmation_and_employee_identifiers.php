<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('dni')->nullable()->unique()->after('id');
            $table->string('bank_account_number')->nullable()->unique()->after('dni');
        });

        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            $table->boolean('assigned_overtime_fulfilled')
                ->default(false)
                ->after('assigned_overtime_seconds');
        });

        DB::table('daily_time_reviews')
            ->where('possible_overtime_seconds', '>', 0)
            ->update(['assigned_overtime_fulfilled' => true]);
    }

    public function down(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            $table->dropColumn('assigned_overtime_fulfilled');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['dni']);
            $table->dropUnique(['bank_account_number']);
            $table->dropColumn(['dni', 'bank_account_number']);
        });
    }
};
