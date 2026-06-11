<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CampaignSeeder::class,
            TeamSeeder::class,
            DepartmentSeeder::class,
            WorkRoleSeeder::class,
            ScheduleTypeSeeder::class,
            ContractTypeSeeder::class,
            HourlyRateTypeSeeder::class,
            TierLevelSeeder::class,
            DeductionTypeSeeder::class,
            PaidTimeProjectSeeder::class,
            UserSeeder::class,
            EmployeeSeeder::class,
        ]);
    }
}
