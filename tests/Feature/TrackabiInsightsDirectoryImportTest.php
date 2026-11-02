<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Services\Trackabi\TrackabiInsightsDirectoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrackabiInsightsDirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        Storage::fake('local');
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        Employee::query()->create([
            'name' => 'Rene Refsman', 'trackabi_name' => 'Refsman, Rene',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
        ]);
        $this->period = PayrollPeriod::query()->create([
            'name' => 'Dos días', 'starts_at' => '2026-10-21', 'ends_at' => '2026-10-22', 'status' => 'borrador',
        ]);
    }

    private function csv(string $date, string $productive = '06:00'): void
    {
        Storage::disk('local')->put(
            'period-11/'.$date.'-'.$date.'-insights.csv',
            "\"Member Name\",\"Total logged\",\"Total billable\",\"Productive activity time\",\"Unproductive activity time\"\n"
            ."\"Refsman, Rene\",08:00,08:00,{$productive},01:00\n"
            ."\"Delcid, David\",00:00,00:00,00:00,00:00\n",
        );
    }

    public function test_directory_preview_and_commit_are_complete_and_idempotent(): void
    {
        $this->csv('2026-10-21');
        $this->csv('2026-10-22', '06:01');
        $directory = Storage::disk('local')->path('period-11');
        $service = app(TrackabiInsightsDirectoryImportService::class);

        $plan = $service->preview($this->period, $directory);
        $this->assertTrue($plan['safe_to_import']);
        $this->assertSame(2, $plan['matched']);
        $this->assertSame(2, $plan['ignored']);
        $this->assertSame(0, $plan['unmatched']);
        $this->assertSame(0, TrackabiDailyActivity::query()->count());

        $this->assertSame(2, $service->import($this->period, $directory)['written']);
        $this->assertSame(2, TrackabiDailyActivity::query()->count());
        $this->assertSame(0, $service->import($this->period, $directory)['written']);
        $this->csv('2026-10-21', '05:30');
        $this->assertSame(1, $service->import($this->period, $directory)['written']);
        $this->assertSame(2, TrackabiDailyActivity::query()->count());
        $this->assertSame(6 * 3600 + 30 * 60, (int) TrackabiDailyActivity::query()
            ->whereDate('activity_date', '2026-10-21')->sole()->measured_activity_seconds);
    }

    public function test_directory_rejects_missing_or_ambiguous_dates_without_writes(): void
    {
        $this->csv('2026-10-21');
        $service = app(TrackabiInsightsDirectoryImportService::class);
        $this->assertFalse($service->preview($this->period, Storage::disk('local')->path('period-11'))['safe_to_import']);
        try {
            $service->import($this->period, Storage::disk('local')->path('period-11'));
            $this->fail('El lote incompleto debe rechazarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('fechas faltantes', $exception->getMessage());
        }
        $this->assertSame(0, TrackabiDailyActivity::query()->count());
    }
}
