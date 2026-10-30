<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/** Initials avatar rendered locally (no requests to external avatar services). */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = Filament::getNameForDefaultAvatar($record);
        $initials = collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#4f46e5"/>'
            .'<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="26" fill="#fff">'
            .htmlspecialchars($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
