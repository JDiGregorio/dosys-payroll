<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_final_review_operations')->default(false);
        });
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->foreignId('operations_final_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('operations_final_reviewed_at')->nullable();
            $table->string('operations_final_review_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operations_final_reviewed_by');
            $table->dropColumn(['operations_final_reviewed_at', 'operations_final_review_fingerprint']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_final_review_operations');
        });
    }
};
