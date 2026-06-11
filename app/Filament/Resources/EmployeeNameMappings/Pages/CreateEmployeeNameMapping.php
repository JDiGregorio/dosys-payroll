<?php

namespace App\Filament\Resources\EmployeeNameMappings\Pages;

use App\Filament\Resources\EmployeeNameMappings\EmployeeNameMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeNameMapping extends CreateRecord
{
    protected static string $resource = EmployeeNameMappingResource::class;
}
