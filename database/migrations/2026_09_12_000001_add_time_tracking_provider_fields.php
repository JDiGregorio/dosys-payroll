<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubstaff_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('hubstaff_imports', 'source_provider')) {
                $table->string('source_provider')->default('hubstaff_csv')->after('payroll_period_id')->index();
            }
        });

        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('hubstaff_time_entries', 'source_provider')) {
                $table->string('source_provider')->default('hubstaff_csv')->after('hubstaff_import_id')->index();
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'external_id')) {
                $table->string('external_id')->nullable()->after('source_provider')->index();
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'source_email')) {
                $table->string('source_email')->nullable()->after('external_id')->index();
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'source_member_id')) {
                $table->string('source_member_id')->nullable()->after('source_email')->index();
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'source_started_at')) {
                $table->dateTime('source_started_at')->nullable()->after('source_member_id');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'source_ended_at')) {
                $table->dateTime('source_ended_at')->nullable()->after('source_started_at');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'source_time_type')) {
                $table->string('source_time_type')->nullable()->after('source_ended_at');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'billable_seconds')) {
                $table->integer('billable_seconds')->default(0)->after('total_seconds');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'productive_seconds')) {
                $table->integer('productive_seconds')->nullable()->after('billable_seconds');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'unproductive_seconds')) {
                $table->integer('unproductive_seconds')->nullable()->after('productive_seconds');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'activity_score')) {
                $table->decimal('activity_score', 8, 2)->nullable()->after('unproductive_seconds');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'adjusted_payable_seconds')) {
                $table->integer('adjusted_payable_seconds')->nullable()->after('activity_score');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'adjustment_reason')) {
                $table->string('adjustment_reason')->nullable()->after('adjusted_payable_seconds');
            }

            if (! Schema::hasColumn('hubstaff_time_entries', 'requires_manual_review')) {
                $table->boolean('requires_manual_review')->default(false)->after('adjustment_reason')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $columns = [
                'source_provider',
                'external_id',
                'source_email',
                'source_member_id',
                'source_started_at',
                'source_ended_at',
                'source_time_type',
                'billable_seconds',
                'productive_seconds',
                'unproductive_seconds',
                'activity_score',
                'adjusted_payable_seconds',
                'adjustment_reason',
                'requires_manual_review',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('hubstaff_time_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('hubstaff_imports', function (Blueprint $table): void {
            if (Schema::hasColumn('hubstaff_imports', 'source_provider')) {
                $table->dropColumn('source_provider');
            }
        });
    }
};
