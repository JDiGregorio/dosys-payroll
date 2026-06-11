<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'campaign')) {
                $table->dropIndex(['campaign']);
            }

            if (Schema::hasColumn('employees', 'team')) {
                $table->dropIndex(['team']);
            }

            foreach (['department', 'campaign', 'team', 'role', 'tier_level', 'schedule_type'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'department')) {
                $table->string('department')->nullable()->after('name');
            }

            if (! Schema::hasColumn('employees', 'campaign')) {
                $table->string('campaign')->nullable()->after('department');
            }

            if (! Schema::hasColumn('employees', 'team')) {
                $table->string('team')->nullable()->after('campaign');
            }

            if (! Schema::hasColumn('employees', 'role')) {
                $table->string('role')->nullable()->after('team');
            }

            if (! Schema::hasColumn('employees', 'tier_level')) {
                $table->string('tier_level')->nullable()->after('role');
            }

            if (! Schema::hasColumn('employees', 'schedule_type')) {
                $table->string('schedule_type')->nullable()->after('tier_level');
            }
        });
    }
};
