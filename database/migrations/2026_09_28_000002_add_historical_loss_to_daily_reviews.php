<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table) {
            $table->unsignedInteger('estimated_lost_seconds')->default(0);
            $table->integer('supervisor_adjustment_seconds')->default(0);
            $table->string('lost_time_source')->nullable();
            $table->json('lost_time_estimate_metadata')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('daily_review_lost_time_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_time_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_review_lost_time_events');

        Schema::table('daily_time_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_lost_seconds',
                'supervisor_adjustment_seconds',
                'lost_time_source',
                'lost_time_estimate_metadata',
                'reviewed_at',
            ]);
        });
    }
};
