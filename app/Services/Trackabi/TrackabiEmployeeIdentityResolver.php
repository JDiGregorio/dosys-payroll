<?php

namespace App\Services\Trackabi;

use App\Models\Employee;
use Illuminate\Support\Collection;

class TrackabiEmployeeIdentityResolver
{
    /** @param Collection<int, Employee> $employees */
    public function resolve(Collection $employees, ?int $memberId, ?string $email, ?string $name): ?Employee
    {
        if ($memberId !== null) {
            $matches = $employees->filter(fn (Employee $employee): bool => (int) $employee->trackabi_member_id === $memberId);
            if ($matches->count() !== 1) {
                return null;
            }

            return $matches->first();
        }

        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $matches = $employees->filter(fn (Employee $employee): bool =>
                strtolower(trim((string) $employee->trackabi_email)) === $email
                || strtolower(trim((string) $employee->email)) === $email);
            if ($matches->count() > 1) {
                return null;
            }
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $matches = $employees->filter(fn (Employee $employee): bool => $employee->trackabi_name === $name);

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
