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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('hubstaff_name')->nullable()->index();
            $table->string('name');
            $table->string('department')->nullable();
            $table->string('campaign')->nullable()->index();
            $table->string('team')->nullable()->index();
            $table->string('role')->nullable();
            $table->string('tier_level')->nullable();
            $table->string('schedule_type')->nullable();
            $table->decimal('weekly_hours', 8, 2)->default(0);
            $table->decimal('daily_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('hourly_rate', 12, 4)->default(0);
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('expected_days', 8, 2)->default(0);
            $table->decimal('expected_total', 12, 2)->default(0);
            $table->decimal('qa_bonus', 12, 2)->default(0);
            $table->decimal('productivity_bonus', 12, 2)->default(0);
            $table->decimal('time_management_bonus', 12, 2)->default(0);
            $table->string('location')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
