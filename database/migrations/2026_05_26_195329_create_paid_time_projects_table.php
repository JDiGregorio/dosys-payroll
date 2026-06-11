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
        Schema::create('paid_time_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('match_type', ['exact', 'contains'])->default('exact');
            $table->enum('category', ['break', 'lunch', 'pto', 'holiday', 'training', 'meeting', 'coaching', 'other'])->default('other');
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_review')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paid_time_projects');
    }
};
