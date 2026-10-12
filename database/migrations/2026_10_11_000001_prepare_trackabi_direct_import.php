<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trackabi_project_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trackabi_project_id')->unique();
            $table->string('trackabi_project_name')->nullable();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('trackabi_project_id')->nullable()->index();
            $table->string('direct_external_id')->nullable();
            $table->unique(['payroll_period_id', 'direct_external_id'], 'trackabi_direct_period_external_unique');
        });

        Schema::create('tracking_import_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('source_provider');
            $table->string('code');
            $table->json('context')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
            $table->index(['payroll_period_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_import_alerts');

        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->dropUnique('trackabi_direct_period_external_unique');
            $table->dropColumn(['trackabi_project_id', 'direct_external_id']);
        });

        Schema::dropIfExists('trackabi_project_mappings');
    }
};
