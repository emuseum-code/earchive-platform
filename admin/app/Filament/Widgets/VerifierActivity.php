<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Робота верифікаторів: скільки записів звірено, зауважень і AI-перевірок — для планування звірки великого масиву. */
class VerifierActivity extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Робота верифікаторів';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $today = now()->startOfDay();
        $week = now()->subDays(7);

        return $table
            ->query(fn () => User::query()
                ->where(fn (Builder $q) => $q->whereIn('role', [Role::Verifier->value, Role::Admin->value])
                    ->orWhere(fn (Builder $w) => $w->where('role', Role::Operator->value)->where('can_verify', true)))
                ->withCount([
                    'verifiedRecords as verified_today' => fn (Builder $q) => $q->where('verified_at', '>=', $today),
                    'verifiedRecords as verified_week' => fn (Builder $q) => $q->where('verified_at', '>=', $week),
                    'verifiedRecords as verified_total',
                    'remarks as remarks_week' => fn (Builder $q) => $q->where('created_at', '>=', $week),
                    'aiChecks as ai_week' => fn (Builder $q) => $q->where('created_at', '>=', $week),
                ]))
            ->defaultSort('verified_week', 'desc')
            ->paginated([10, 25])
            ->description('Підтверджені звірки, зауваження та запуски «Перевірити AI» за сьогодні й останні 7 днів.')
            ->columns([
                TextColumn::make('name')->label('Працівник')->description(fn (User $u) => $u->role->getLabel())->searchable(),
                TextColumn::make('verified_today')->label('Звірено сьогодні')->alignEnd()->sortable(),
                TextColumn::make('verified_week')->label('За 7 днів')->alignEnd()->sortable()->weight('medium'),
                TextColumn::make('verified_total')->label('Усього')->alignEnd()->sortable(),
                TextColumn::make('remarks_week')->label('Зауважень за 7 днів')->alignEnd()->sortable(),
                TextColumn::make('ai_week')->label('AI-перевірок за 7 днів')->alignEnd()->sortable(),
                TextColumn::make('last_login_at')->label('Останній вхід')->since()->placeholder('—')->sortable(),
            ]);
    }
}
