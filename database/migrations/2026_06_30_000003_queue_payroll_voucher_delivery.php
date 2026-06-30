<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_results', 'voucher_delivery_status')) {
                $table->string('voucher_delivery_status')->default('pending')->after('status');
            }

            if (! Schema::hasColumn('payroll_results', 'voucher_queued_at')) {
                $table->timestamp('voucher_queued_at')->nullable()->after('voucher_delivery_status');
            }

            if (! Schema::hasColumn('payroll_results', 'voucher_failed_at')) {
                $table->timestamp('voucher_failed_at')->nullable()->after('voucher_sent_to');
            }

            if (! Schema::hasColumn('payroll_results', 'voucher_error')) {
                $table->text('voucher_error')->nullable()->after('voucher_failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn([
                'voucher_delivery_status',
                'voucher_queued_at',
                'voucher_failed_at',
                'voucher_error',
            ]);
        });
    }
};
