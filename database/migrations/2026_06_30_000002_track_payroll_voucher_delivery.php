<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_results', 'voucher_sent_at')) {
                $table->timestamp('voucher_sent_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('payroll_results', 'voucher_sent_to')) {
                $table->string('voucher_sent_to')->nullable()->after('voucher_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn(['voucher_sent_at', 'voucher_sent_to']);
        });
    }
};
