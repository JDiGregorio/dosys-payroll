<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeNameMapping;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HubstaffEmployeeMappingService
{
    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
    ) {}

    public function map(PayrollPeriod $period, string $hubstaffMember, int $employeeId): int
    {
        $hubstaffMember = trim($hubstaffMember);

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('active', true)
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Selecciona un empleado activo.',
            ]);
        }

        $hasUnmappedEntries = HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('hubstaff_member', $hubstaffMember)
            ->whereNull('employee_id')
            ->exists();

        if ($hubstaffMember === '' || ! $hasUnmappedEntries) {
            throw ValidationException::withMessages([
                'hubstaff_member' => 'Este nombre ya no tiene registros pendientes de mapeo en el período.',
            ]);
        }

        return DB::transaction(function () use ($period, $hubstaffMember, $employee): int {
            EmployeeNameMapping::query()->updateOrCreate(
                ['hubstaff_member' => $hubstaffMember],
                [
                    'employee_id' => $employee->id,
                    'confidence' => 100,
                    'is_active' => true,
                ],
            );

            $updatedEntries = HubstaffTimeEntry::query()
                ->where('payroll_period_id', $period->id)
                ->where('hubstaff_member', $hubstaffMember)
                ->whereNull('employee_id')
                ->update(['employee_id' => $employee->id]);

            $this->payrollCalculationService->generateDailyReviews($period);
            $this->payrollCalculationService->recalculatePayrollResults($period);
            $period->update(['status' => 'en_revision']);

            return $updatedEntries;
        });
    }
}
