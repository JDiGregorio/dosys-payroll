<?php

namespace App\Services\Trackabi;

use RuntimeException;

class TrackabiDirectApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        // Do not chain HTTP exceptions: their request/body may contain credentials.
        parent::__construct($message);
    }
}
