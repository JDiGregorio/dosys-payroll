<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trackabi_activity_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->date('activity_date');
            $table->string('filename');
            $table->char('file_hash', 64);
            $table->unsignedInteger('rows');
            $table->unsignedInteger('matched');
            $table->unsignedInteger('unmatched');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['payroll_period_id', 'activity_date', 'file_hash'], 'trackabi_insights_batch_unique');
        });

        Schema::create('trackabi_daily_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained('trackabi_activity_import_batches')->restrictOnDelete();
            $table->date('activity_date');
            $table->unsignedInteger('productive_activity_seconds');
            $table->unsignedInteger('unproductive_activity_seconds');
            $table->unsignedInteger('measured_activity_seconds');
            $table->unsignedInteger('activity_over_target_seconds')->default(0);
            $table->string('source')->default('trackabi_insights_csv');
            $table->json('raw_metadata')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
            $table->unique(['employee_id', 'activity_date', 'source'], 'trackabi_daily_activity_unique');
            $table->index(['payroll_period_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trackabi_daily_activities');
        Schema::dropIfExists('trackabi_activity_import_batches');
    }
};
