<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rotating = DB::table('schedule_types')->where('code', 'rotativa')->first();
        $legacy = DB::table('schedule_types')->where('code', '4x4')->first();

        if (! $rotating && $legacy) {
            DB::table('schedule_types')
                ->where('id', $legacy->id)
                ->update([
                    'name' => 'Rotativa',
                    'code' => 'rotativa',
                ]);

            $rotating = DB::table('schedule_types')->where('id', $legacy->id)->first();
            $legacy = null;
        }

        if (! $rotating) {
            return;
        }

        if ($legacy) {
            DB::table('employees')
                ->where('schedule_type_id', $legacy->id)
                ->update(['schedule_type_id' => $rotating->id]);
            DB::table('tier_levels')
                ->where('schedule_type_id', $legacy->id)
                ->update(['schedule_type_id' => $rotating->id]);
            DB::table('schedule_types')->where('id', $legacy->id)->delete();
        }

        DB::table('schedule_types')
            ->where('id', $rotating->id)
            ->update([
                'name' => 'Rotativa',
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'weekly_hours' => 40,
                'daily_hours' => 10,
                'description' => 'Jornada rotativa: cuatro días corridos con 10 horas ordinarias y 1 hora extra diaria, seguidos por cuatro días corridos de descanso.',
                'active' => true,
            ]);
    }

    public function down(): void
    {
        DB::table('schedule_types')->updateOrInsert(
            ['code' => '4x4'],
            [
                'name' => 'Modalidad 4x4',
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'weekly_hours' => 40,
                'daily_hours' => 10,
                'description' => 'Jornada 4x4.',
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
