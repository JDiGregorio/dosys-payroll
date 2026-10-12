<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackabiMemberSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_apply_links_only_unique_email_without_touching_hubstaff(): void
    {
        config([
            'trackabi_direct.enabled' => true,
            'trackabi_direct.api_key' => 'test-key',
            'trackabi_direct.base_url' => 'https://api.trackabi.test',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Edwin Rodriguez', 'email' => 'edwin@example.test',
            'hubstaff_name' => 'Edwin Hubstaff', 'active' => true,
        ]);
        $exempt = Employee::query()->create([
            'name' => 'Jonathan Garcia', 'email' => 'jonathan@example.test',
            'active' => true, 'paid_without_tracking' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.trackabi.test/api/v1/members*' => Http::response([
            ['id' => 123, 'first_name' => 'Edwin', 'last_name' => 'Rodriguez', 'email' => 'edwin@example.test'],
            ['id' => 124, 'first_name' => 'Unknown', 'last_name' => 'Person', 'email' => 'unknown@example.test'],
            ['id' => 125, 'first_name' => 'Jonathan', 'last_name' => 'Garcia', 'email' => 'jonathan@example.test'],
        ])]);

        $this->artisan('trackabi:sync-members', ['--dry-run' => true])
            ->expectsOutputToContain('Exact email: 1')->assertSuccessful();
        $this->assertNull($employee->fresh()->trackabi_member_id);
        $this->assertNull($employee->fresh()->trackabi_name);

        $this->artisan('trackabi:sync-members', ['--apply' => true])
            ->expectsOutputToContain('enlazados: 1')->assertSuccessful();
        $this->assertSame(123, (int) $employee->fresh()->trackabi_member_id);
        $this->assertSame('edwin@example.test', $employee->fresh()->trackabi_email);
        $this->assertSame('Rodriguez, Edwin', $employee->fresh()->trackabi_name);
        $this->assertSame('Edwin Hubstaff', $employee->fresh()->hubstaff_name);
        $this->assertNull($exempt->fresh()->trackabi_member_id);

        $this->artisan('trackabi:sync-members', ['--apply' => true])
            ->expectsOutputToContain('enlazados: 0')->assertSuccessful();
    }
}
