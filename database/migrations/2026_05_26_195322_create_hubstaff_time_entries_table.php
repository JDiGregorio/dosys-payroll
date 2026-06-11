<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hubstaff_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hubstaff_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hubstaff_member')->index();
            $table->date('date')->index();
            $table->string('client')->nullable();
            $table->string('project')->nullable()->index();
            $table->string('team')->nullable();
            $table->string('task_id')->nullable();
            $table->string('todo')->nullable();
            $table->integer('regular_seconds')->default(0);
            $table->integer('total_seconds')->default(0);
            $table->integer('idle_seconds')->default(0);
            $table->integer('pto_seconds')->default(0);
            $table->integer('holiday_seconds')->default(0);
            $table->decimal('activity_percentage', 8, 2)->nullable();
            $table->decimal('idle_percentage', 8, 2)->nullable();
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->decimal('regular_spent', 12, 2)->default(0);
            $table->decimal('pto_spent', 12, 2)->default(0);
            $table->decimal('holiday_spent', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hubstaff_time_entries');
    }
};
