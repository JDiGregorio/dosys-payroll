<?php

use App\Models\PaidTimeProject;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PaidTimeProject::query()
            ->whereIn('category', ['pto', 'holiday'])
            ->update(['active' => false]);
    }

    public function down(): void
    {
        //
    }
};
