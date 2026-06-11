<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubstaff_project_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('hubstaff_project')->unique();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubstaff_project_mappings');
    }
};
