<?php

namespace Tests\Unit;

use App\Services\TimeParserService;
use PHPUnit\Framework\TestCase;

class TimeParserServiceTest extends TestCase
{
    public function test_it_parses_supported_time_formats(): void
    {
        $service = new TimeParserService;

        $this->assertSame(0, $service->parseToSeconds(null));
        $this->assertSame(0, $service->parseToSeconds(''));
        $this->assertSame(27000, $service->parseToSeconds('7.5'));
        $this->assertSame(27000, $service->parseToSeconds('07:30'));
        $this->assertSame(27015, $service->parseToSeconds('07:30:15'));
        $this->assertSame(3900, $service->parseToSeconds('1:05'));
        $this->assertSame('7:30', $service->secondsToHourMinute(27000));
        $this->assertSame('7:55', $service->secondsToHourMinute(28470));
        $this->assertSame('7:54:30', $service->secondsToHourMinuteSecond(28470));
        $this->assertSame(7.5, $service->secondsToDecimalHours(27000));
    }
}
