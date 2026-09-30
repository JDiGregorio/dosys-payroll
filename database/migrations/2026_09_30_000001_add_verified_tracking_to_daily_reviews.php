<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table) {
            $table->unsignedInteger('verified_tracked_seconds')->nullable();
            $table->string('verified_tracked_source')->nullable();
            $table->text('verified_tracked_note')->nullable();
            $table->timestamp('verified_tracked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_time_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'verified_tracked_seconds',
                'verified_tracked_source',
                'verified_tracked_note',
                'verified_tracked_at',
            ]);
        });
    }
};
