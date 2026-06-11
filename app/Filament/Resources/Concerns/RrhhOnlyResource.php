<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

trait RrhhOnlyResource
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }
}
