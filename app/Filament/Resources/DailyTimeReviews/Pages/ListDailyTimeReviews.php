<?php

namespace App\Filament\Resources\DailyTimeReviews\Pages;

use App\Filament\Pages\DailyReviewCalendar;
use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListDailyTimeReviews extends ListRecords
{
    protected static string $resource = DailyTimeReviewResource::class;

    public function mount(): void
    {
        $this->redirect(DailyReviewCalendar::getUrl(), navigate: true);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
