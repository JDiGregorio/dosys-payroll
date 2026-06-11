<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hubstaff_project_mappings', 'team_id')) {
            return;
        }

        Schema::table('hubstaff_project_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('hubstaff_project_mappings', function (Blueprint $table): void {
            if (! Schema::hasColumn('hubstaff_project_mappings', 'team_id')) {
                $table->foreignId('team_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
            }
        });
    }
};
