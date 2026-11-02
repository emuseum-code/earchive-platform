<?php

namespace App\Filament\Support;

use Filament\Actions\Action;

/** Єдиний вигляд дій, які запускають штучний інтелект: яскраво-синя кнопка з іконкою ✦. */
class Ai
{
    public static function button(Action $action): Action
    {
        return $action->icon('heroicon-s-sparkles')->color('ai')->button()
            ->extraAttributes(['class' => 'bk-ai-action'], merge: true);
    }

    /** Масові дії й пункти меню: лише колір та іконка (вони показуються списком). */
    public static function item(Action $action): Action
    {
        return $action->icon('heroicon-s-sparkles')->color('ai');
    }
}
