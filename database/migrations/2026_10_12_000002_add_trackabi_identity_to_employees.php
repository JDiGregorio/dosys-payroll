<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedBigInteger('trackabi_member_id')->nullable()->unique();
            $table->string('trackabi_email')->nullable()->index();
            $table->string('trackabi_name')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['trackabi_member_id']);
            $table->dropIndex(['trackabi_email']);
            $table->dropIndex(['trackabi_name']);
            $table->dropColumn(['trackabi_member_id', 'trackabi_email', 'trackabi_name']);
        });
    }
};
