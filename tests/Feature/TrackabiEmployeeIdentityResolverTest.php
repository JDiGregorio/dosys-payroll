<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Trackabi\TrackabiEmployeeIdentityResolver;
use Tests\TestCase;

class TrackabiEmployeeIdentityResolverTest extends TestCase
{
    public function test_resolves_member_id_email_and_exact_insights_name_in_priority_order(): void
    {
        $first = new Employee([
            'name' => 'Edwin Rodriguez', 'email' => 'edwin@payroll.test',
            'hubstaff_name' => 'Old Hubstaff Edwin', 'trackabi_member_id' => 101,
            'trackabi_email' => 'edwin@trackabi.test', 'trackabi_name' => 'Rodriguez, Edwin',
        ]);
        $second = new Employee([
            'name' => 'Jose Estrada', 'email' => 'jose@payroll.test',
            'trackabi_member_id' => 102, 'trackabi_name' => 'Estrada, Jose',
        ]);
        $employees = collect([$first, $second]);
        $resolver = app(TrackabiEmployeeIdentityResolver::class);

        $this->assertSame($first, $resolver->resolve($employees, 101, 'jose@payroll.test', 'Estrada, Jose'));
        $this->assertSame($first, $resolver->resolve($employees, null, 'EDWIN@TRACKABI.TEST', null));
        $this->assertSame($first, $resolver->resolve($employees, null, 'edwin@payroll.test', null));
        $this->assertSame($second, $resolver->resolve($employees, null, null, 'Estrada, Jose'));
        $this->assertNull($resolver->resolve($employees, null, null, 'estrada, jose'));
        $this->assertNull($resolver->resolve($employees, null, null, 'Old Hubstaff Edwin'));
        $this->assertNull($resolver->resolve($employees, 999, 'edwin@payroll.test', 'Rodriguez, Edwin'));
    }

    public function test_ambiguous_trackabi_name_or_email_remains_unmapped(): void
    {
        $employees = collect([
            new Employee(['email' => 'same@payroll.test', 'trackabi_name' => 'Lara, Marco']),
            new Employee(['email' => 'other@payroll.test', 'trackabi_email' => 'same@payroll.test', 'trackabi_name' => 'Lara, Marco']),
        ]);
        $resolver = app(TrackabiEmployeeIdentityResolver::class);

        $this->assertNull($resolver->resolve($employees, null, 'same@payroll.test', 'Lara, Marco'));
        $this->assertNull($resolver->resolve($employees, null, null, 'Lara, Marco'));
        $this->assertNull($resolver->resolve($employees, null, null, 'Unknown, Person'));
    }
}
