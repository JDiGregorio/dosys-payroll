<?php

namespace App\Services\Trackabi;

interface TrackabiActivityProvider
{
    public function source(): string;

    public function normalize(array $values): DailyTrackabiActivityData;
}
