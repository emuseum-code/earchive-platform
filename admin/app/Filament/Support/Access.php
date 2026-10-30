<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Права на рівні ресурсу (ТЗ 5, 18). Filament перевіряє їх і на сервері:
 * пряма адреса чи прихована дія для ролі без прав повертає 403.
 */
trait Access
{
    /** Хто бачить розділ: all | staff | operate | verify | adapt | admin */
    protected static function viewAccess(): string
    {
        return 'all';
    }

    /** Хто створює/редагує: none | operate | verify | adapt | admin */
    protected static function editAccess(): string
    {
        return 'admin';
    }

    protected static function allowed(string $level): bool
    {
        $u = auth()->user();

        return $u && match ($level) {
            'all' => true,
            'operate' => $u->canOperate(),
            'verify' => $u->canVerify(),
            'adapt' => $u->canAdaptExport(),
            'staff' => $u->canOperate() || $u->canVerify(),
            'admin' => $u->isAdmin(),
            default => false,
        };
    }

    public static function canViewAny(): bool
    {
        return static::allowed(static::viewAccess());
    }

    public static function canView(Model $record): bool
    {
        return static::allowed(static::viewAccess());
    }

    public static function canCreate(): bool
    {
        return static::allowed(static::editAccess());
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed(static::editAccess());
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() && static::editAccess() !== 'none';
    }

    public static function canDeleteAny(): bool
    {
        return static::canDelete(new class extends Model {});
    }
}
