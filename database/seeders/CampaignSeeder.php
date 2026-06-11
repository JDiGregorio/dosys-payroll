<?php

namespace Database\Seeders;

use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['PALMETTO', 'RRD FINANCIAL', 'DIGITAL OX', 'AUTO FINANCE CENTER'] as $name) {
            Campaign::query()->updateOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
