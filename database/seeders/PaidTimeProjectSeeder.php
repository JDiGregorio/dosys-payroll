<?php

namespace Database\Seeders;

use App\Models\PaidTimeProject;
use Illuminate\Database\Seeder;

class PaidTimeProjectSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [
            ['name' => 'Break 1', 'match_type' => 'exact', 'category' => 'break', 'is_paid' => true, 'requires_review' => false],
            ['name' => 'Break 2', 'match_type' => 'exact', 'category' => 'break', 'is_paid' => true, 'requires_review' => false],
            ['name' => '10 Min Break', 'match_type' => 'contains', 'category' => 'break', 'is_paid' => true, 'requires_review' => false],
            ['name' => 'Lunch', 'match_type' => 'contains', 'category' => 'lunch', 'is_paid' => true, 'requires_review' => false],
            ['name' => 'Training', 'match_type' => 'contains', 'category' => 'training', 'is_paid' => true, 'requires_review' => true],
            ['name' => 'Meeting', 'match_type' => 'contains', 'category' => 'meeting', 'is_paid' => true, 'requires_review' => true],
            ['name' => 'Coaching', 'match_type' => 'contains', 'category' => 'coaching', 'is_paid' => true, 'requires_review' => true],
        ];

        foreach ($projects as $project) {
            PaidTimeProject::query()->updateOrCreate(
                ['name' => $project['name']],
                $project + ['active' => true],
            );
        }
    }
}
