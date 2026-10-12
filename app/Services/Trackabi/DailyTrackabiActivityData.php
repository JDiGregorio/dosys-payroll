<?php

namespace App\Services\Trackabi;

final readonly class DailyTrackabiActivityData
{
    public function __construct(
        public int $productiveSeconds,
        public int $unproductiveSeconds,
        public string $source,
    ) {}

    public function measuredSeconds(): int
    {
        return $this->productiveSeconds + $this->unproductiveSeconds;
    }
}
