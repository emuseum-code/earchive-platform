<?php

namespace App\Filament\Resources;

use App\Enums\AdaptationStatus;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\JobType;
use App\Enums\VerificationStatus;
use App\Filament\Resources\RecordResource\Pages;
use App\Filament\Support\Access;
use App\Models\Book;
use App\Models\Export;
use App\Models\Fund;
use App\Models\Museum;
use App\Models\Record;
use App\Services\QueueManager;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Єдина таблиця розпізнаних даних: один рядок — один обліковий запис книги (ТЗ 7). */
class RecordResource extends Resource
{
    use Access;

    protected static function editAccess(): string
    {
        return 'verify';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Record::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Дані та звірка';

    protected static ?string $modelLabel = 'запис';

    protected static ?string $pluralModelLabel = 'Єдина таблиця записів';

    protected static ?string $navigationLabel = 'Єдина таблиця';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false; // записи створює розпізнавання; пропущений запис позначається зауваженням
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['book', 'museum', 'fund']);
    }

    // Пошук у верхньому рядку (ТЗ 18.4): за номером запису, обліковим та інвентарним номером, назвою
    protected static ?string $recordTitleAttribute = 'record_no';

    public static function getGloballySearchableAttributes(): array
    {
        return ['record_no', 'account_number', 'inventory_number', 'title'];
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        return 'Запис № '.$record->record_no.($record->title ? ' · '.\Illuminate\Support\Str::limit($record->title, 60) : '');
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return array_filter(['Книга' => $record->book?->title, 'Інв. №' => $record->inventory_number, 'Звірка' => $record->verification_status?->getLabel()]);
    }

    public static function getGlobalSearchResultUrl(\Illuminate\Database\Eloquent\Model $record): string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Record::visibleTo(auth()->user())->where('verification_status', VerificationStatus::NeedsClarification->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Потребують уточнення';
    }

    public static function form(Schema $schema): Schema
    {
        $groups = collect(Record::FIELDS)->groupBy(fn ($f) => $f[1], true);

        return $schema->components([
            ...$groups->map(fn ($fields, $group) => Section::make($group)->columnSpanFull()->schema([Grid::make(3)->schema(
                collect($fields)->map(fn ($f, $key) => in_array($key, ['description', 'title', 'acquisition_source', 'provenance', 'notes', 'material', 'location'], true)
                    ? Textarea::make($key)->label($f[0])->rows($key === 'description' ? 5 : 2)->columnSpan($key === 'description' ? 3 : 1)
                    : TextInput::make($key)->label($f[0]))->values()->all()
            )]))->values()->all(),
            Section::make('Додаткові поля з книги')->columnSpanFull()->collapsible()->schema([
                KeyValue::make('extra_fields')->hiddenLabel()->keyLabel('Назва графи')->valueLabel('Значення'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $cols = [
            TextColumn::make('book.title')->label('Книга')->toggleable()->wrap()->size('xs')
                ->description(fn (Record $r) => $r->museum?->name),
        ];
        $visible = ['record_no', 'inventory_number', 'account_number', 'title', 'material', 'dimensions', 'condition'];
        foreach (Record::FIELDS as $key => [$label]) {
            $c = TextColumn::make($key)->label($label)->toggleable(isToggledHiddenByDefault: ! in_array($key, $visible, true))->placeholder('—');
            if (in_array($key, ['title', 'description', 'acquisition_source', 'material', 'notes', 'provenance'], true)) {
                $c->wrap()->lineClamp(3)->extraAttributes(['style' => 'min-width:16rem']);
            }
            if (in_array($key, ['record_no', 'inventory_number', 'account_number'], true)) {
                $c->searchable()->sortable();
            }
            $cols[] = $c;
        }
        $cols[] = TextColumn::make('verification_status')->label('Звірка')->badge()->sortable();
        $cols[] = TextColumn::make('adaptation_status')->label('Адаптація')->badge()->toggleable();
        $cols[] = TextColumn::make('export_status')->label('Експорт')->badge()->toggleable(isToggledHiddenByDefault: true);
        $cols[] = IconColumn::make('possible_duplicate')->label('Дубль?')->boolean()->trueIcon('heroicon-o-document-duplicate')->trueColor('warning')
            ->falseIcon('')->toggleable();
        $cols[] = TextColumn::make('uncertain_fields')->label('Нерозбірливо')->state(fn (Record $r) => count($r->uncertain_fields ?? []) ?: null)
            ->badge()->color('warning')->toggleable();
        $cols[] = TextColumn::make('lock_version')->label('Версія')->alignEnd()->toggleable(isToggledHiddenByDefault: true);
        $cols[] = TextColumn::make('updated_at')->label('Змінено')->since()->sortable()->toggleable(isToggledHiddenByDefault: true);

        return $table
            ->defaultSort(fn ($query) => $query->orderBy('book_id')->orderBy('sequence'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Текст, № запису, інвентарний чи обліковий номер')
            ->searchable()
            ->searchUsing(fn (Builder $q, string $search) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")
                ->orWhere('inventory_number', 'like', "%{$search}%")->orWhere('account_number', 'like', "%{$search}%")
                ->orWhere('record_no', 'like', "%{$search}%")->orWhere('acquisition_source', 'like', "%{$search}%")))
            ->columns($cols)
            ->filters([
                SelectFilter::make('museum_id')->label('Музей')->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('book_id')->label('Книга')->options(fn () => Book::visibleTo(auth()->user())->orderBy('title')->pluck('title', 'id'))->searchable(),
                SelectFilter::make('fund_id')->label('Фонд')->options(fn () => Fund::pluck('name', 'id')),
                SelectFilter::make('verification_status')->label('Звірка')->options(VerificationStatus::options())->multiple(),
                SelectFilter::make('adaptation_status')->label('Адаптація')->options(AdaptationStatus::options())->multiple(),
                SelectFilter::make('export_status')->label('Експорт')->options(ExportStatus::options())->multiple(),
                TernaryFilter::make('possible_duplicate')->label('Можливий дублікат'),
                Filter::make('uncertain')->label('Є нерозбірливі поля')->query(fn ($query) => $query->whereNotNull('uncertain_fields')),
                Filter::make('open_remarks')->label('Є відкриті зауваження')->query(fn ($query) => $query->whereHas('openRemarks')),
            ])
            ->filtersFormColumns(3)
            ->recordUrl(fn (Record $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([
                Action::make('review')->label('Провести звірку')->icon('heroicon-o-document-magnifying-glass')
                    ->url(fn (Record $r) => static::getUrl('review', ['record' => $r])),
                ViewAction::make()->label(''),
            ])
            ->headerActions([
                Action::make('exportFiltered')->label('Експорт відфільтрованого')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->visible(fn () => auth()->user()->canAdaptExport())
                    ->schema([Select::make('format')->label('Формат')->options(static::exportFormats())->required()->default('xlsx')])
                    ->action(function (array $data, $livewire) {
                        $filters = collect($livewire->tableFilters ?? [])->map(fn ($f) => $f['value'] ?? $f['values'] ?? null)->filter()->all();
                        $filters['search'] = $livewire->getTableSearch();
                        static::startExport($data['format'], array_filter($filters));
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('adapt')->label('Адаптувати до реєстру')->icon('heroicon-o-arrows-right-left')
                    ->visible(fn () => auth()->user()->canAdaptExport())->requiresConfirmation()
                    ->modalDescription('Адаптуються лише записи зі статусом «Перевірено». Неоднозначності буде передано верифікатору.')
                    ->action(function (Collection $records) {
                        $q = app(QueueManager::class);
                        $n = 0;
                        foreach ($records->where('verification_status', VerificationStatus::Verified) as $r) {
                            $q->enqueue(JobType::Adapt, $r, auth()->user());
                            $n++;
                        }
                        Notification::make()->success()->title("Адаптацію поставлено в чергу: {$n}")
                            ->body($records->count() - $n ? ($records->count() - $n).' неперевірених пропущено' : null)->send();
                    }),
                BulkAction::make('export')->label('Експортувати вибрані')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()->canAdaptExport())
                    ->schema([Select::make('format')->label('Формат')->options(static::exportFormats())->required()->default('xlsx')])
                    ->action(fn (Collection $records, array $data) => static::startExport($data['format'], ['ids' => $records->pluck('id')->all()])),
            ]);
    }

    public static function exportFormats(): array
    {
        return collect(ExportFormat::cases())->reject(fn ($f) => $f === ExportFormat::Api)->mapWithKeys(fn ($f) => [$f->value => $f->getLabel()])->all();
    }

    public static function startExport(string $format, array $filters): void
    {
        $export = Export::create(['user_id' => auth()->id(), 'format' => $format, 'status' => 'queued', 'filters' => $filters]);
        app(QueueManager::class)->enqueue(JobType::Export, $export, auth()->user(), priority: 50);
        Notification::make()->success()->title('Експорт формується')->body('Файл з’явиться в розділі «Експорт».')
            ->actions([Action::make('open')->label('Відкрити')->url(ExportResource::getUrl())])->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecordRows::route('/'),
            'view' => Pages\ViewRecordCard::route('/{record}'),
            'edit' => Pages\EditRecordData::route('/{record}/edit'),
            'review' => Pages\ReviewRecord::route('/{record}/review'),
        ];
    }
}
