<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'employee_code')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_employee_code_unique');
            $table->dropIndex('employees_employee_code_index');
            $table->dropColumn('employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'employee_code')) {
                $table->string('employee_code')->nullable()->after('id')->unique();
            }
        });
    }
};
