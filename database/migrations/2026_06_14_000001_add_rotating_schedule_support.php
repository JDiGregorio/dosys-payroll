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
            $table->date('schedule_cycle_anchor_date')->nullable()->after('schedule_type_id');
        });

        DB::table('schedule_types')
            ->whereIn('code', ['rotativa', '4x4'])
            ->update([
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'weekly_hours' => 40,
                'daily_hours' => 10,
                'description' => 'Jornada 4x4: cuatro días corridos con 10 horas ordinarias y 1 hora extra diaria, seguidos por cuatro días corridos de descanso.',
            ]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('schedule_cycle_anchor_date');
        });
    }
};
