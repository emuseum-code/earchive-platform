<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Filament\Support\Access;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Activity;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Журнал дій користувачів, синхронізацій, AI, шаблонів, адаптацій та експортів (ТЗ 12). */
class ActivityLogResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'admin';
    }

    protected static function editAccess(): string
    {
        return 'none';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = ActivityLog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Адміністрування';

    protected static ?string $modelLabel = 'подія';

    protected static ?string $pluralModelLabel = 'Журнал дій';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('Час')->dateTime('d.m.Y H:i:s')->sortable(),
            TextColumn::make('user.name')->label('Користувач')->placeholder('система'),
            TextColumn::make('action')->label('Дія')->formatStateUsing(fn ($state) => Activity::ACTIONS[$state] ?? $state)->badge()->color('gray'),
            TextColumn::make('subject_type')->label('Об’єкт')->formatStateUsing(fn ($state, ActivityLog $l) => $state ? $state.' #'.$l->subject_id : '—'),
            TextColumn::make('description')->label('Опис')->wrap()->searchable(),
            TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('action')->label('Дія')->options(Activity::ACTIONS)->multiple(),
            SelectFilter::make('user_id')->label('Користувач')->options(fn () => User::pluck('name', 'id')),
            Filter::make('period')->schema([DatePicker::make('from')->label('З'), DatePicker::make('to')->label('По')])
                ->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                    ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListActivityLogs::route('/')];
    }
}
