<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            foreach (['expected_work_days', 'reports_to'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'expected_work_days')) {
                $table->decimal('expected_work_days', 8, 2)->default(0)->after('calendar_days');
            }

            if (! Schema::hasColumn('employees', 'reports_to')) {
                $table->string('reports_to')->nullable()->after('location');
            }
        });
    }
};
