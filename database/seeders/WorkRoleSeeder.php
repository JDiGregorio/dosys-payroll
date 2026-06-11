<?php

namespace Database\Seeders;

use App\Models\WorkRole;
use Illuminate\Database\Seeder;

class WorkRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'APPOINTMENT SETTER',
            'BDC APPOINTMENT SETTER',
            'CEO & HEAD OF HUMAN RESOURCES',
            'CHAT SUPPORT',
            'CHIEF OPERATIONS OFFICER',
            'COORDINATOR',
            'DEBT COLLECTOR',
            'FINANCIAL OPPERATIONS SPECIALIST',
            'LOGISTICS COORDINATOR',
            'MANAGER',
            'OPPERATION MANAGER',
            'PAYMENT TAKER',
            'PROJECT COORDINATOR',
            'QUALITY ASSURANCE ANALIST (QA)',
            'RECRUITMENT ANALYST',
            'SUPERVISOR',
            'TEAM LEADER',
        ] as $name) {
            WorkRole::query()->updateOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
