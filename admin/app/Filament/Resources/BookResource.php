<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Enums\VerificationStatus;
use App\Filament\Resources\BookResource\Pages;
use App\Filament\Resources\BookResource\RelationManagers\ScansRelationManager;
use App\Filament\Support\Access;
use App\Models\Book;
use App\Models\Fund;
use App\Models\Museum;
use App\Models\RecognitionTemplate;
use App\Models\Record;
use App\Services\QueueManager;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Книги та їхні картки (ТЗ 4.1, 10). */
class BookResource extends Resource
{
    use Access;

    protected static function editAccess(): string
    {
        return 'operate';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Book::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $modelLabel = 'книга';

    protected static ?string $pluralModelLabel = 'Книги';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'folder_path'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return array_filter(['Музей' => $record->museum?->name, 'Фонд' => $record->fund?->name]);
    }

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['museum', 'fund', 'template']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columnSpanFull()->schema([Grid::make(3)->schema([
            TextInput::make('title')->label('Назва книги')->required()->columnSpan(2),
            TextInput::make('book_number')->label('Номер / шифр книги'),
            Select::make('museum_id')->label('Музей')->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                ->required()->searchable()->live(),
            Select::make('fund_id')->label('Напрям обліку / фонд')->options(fn (Get $get) => Fund::where('museum_id', $get('museum_id'))->pluck('name', 'id')),
            Select::make('book_type')->label('Тип книги')->options(BookType::options())->required(),
            Select::make('recognition_template_id')->label('Шаблон розпізнавання')->options(fn () => RecognitionTemplate::where('is_active', true)->pluck('name', 'id'))
                ->helperText('Порожньо — за типом книги.'),
            TextInput::make('years')->label('Роки ведення'),
            TextInput::make('language')->label('Мова записів'),
            TextInput::make('expected_pages')->label('Очікувана кількість сторінок')->numeric()
                ->helperText('Без неї наявність сканів не означає повноти книги.'),
            TextInput::make('folder_path')->label('Папка у сховищі')->disabled()->columnSpan(2),
            Textarea::make('notes')->label('Примітки')->rows(2)->columnSpanFull(),
        ])])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([Grid::make(4)->schema([
                TextEntry::make('museum.name')->label('Музей')->columnSpan(2),
                TextEntry::make('fund.name')->label('Напрям обліку / фонд')->placeholder('—'),
                TextEntry::make('book_type')->label('Тип книги')->badge(),
                TextEntry::make('folder_path')->label('Шлях до папки')->fontFamily('mono')->size('xs')->columnSpan(2)->copyable(),
                TextEntry::make('uid')->label('Ідентифікатор')->fontFamily('mono')->size('xs')->copyable(),
                TextEntry::make('template.name')->label('Шаблон розпізнавання')->placeholder('За типом книги')
                    ->suffix(fn (Book $b) => $b->template ? ' · v'.$b->template->version : ''),
                TextEntry::make('scans_count')->label('Сканів')
                    ->suffix(fn (Book $b) => $b->expected_pages ? ' з '.$b->expected_pages.' очікуваних' : ' (очікувана кількість невідома)'),
                TextEntry::make('recognition')->label('Розпізнавання')->state(fn (Book $b) => $b->recognitionProgress().'%')
                    ->helperText(fn (Book $b) => $b->scans_recognized.' сторінок розпізнано'),
                TextEntry::make('records_count')->label('Отримано записів')->helperText(fn (Book $b) => $b->records_verified.' перевірено · '.$b->records_adapted.' адаптовано'),
                TextEntry::make('errors')->label('Помилки')->state(fn (Book $b) => $b->scans_failed + $b->scans_missing)
                    ->color(fn ($state) => $state ? 'danger' : null)
                    ->helperText(fn (Book $b) => $b->scans_failed.' розпізнавання · '.$b->scans_missing.' недоступних файлів'),
                TextEntry::make('synced_at')->label('Синхронізовано')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextEntry::make('notes')->label('Примітки')->placeholder('—')->columnSpan(3),
            ])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Книга')->searchable()->sortable()->weight('medium')->wrap()
                    ->description(fn (Book $b) => $b->museum?->name),
                TextColumn::make('fund.name')->label('Фонд')->toggleable()->placeholder('—'),
                TextColumn::make('book_type')->label('Тип')->badge()->toggleable(),
                TextColumn::make('scans_count')->label('Сканів')->sortable()->alignEnd(),
                TextColumn::make('recognition')->label('Розпізнано')->state(fn (Book $b) => $b->recognitionProgress())
                    ->formatStateUsing(fn ($state) => $state.'%')->alignEnd()
                    ->color(fn ($state) => $state === 100 ? 'success' : ($state ? 'warning' : 'gray')),
                TextColumn::make('records_count')->label('Записів')->sortable()->alignEnd(),
                TextColumn::make('verification')->label('Перевірено')->state(fn (Book $b) => $b->verificationProgress())
                    ->formatStateUsing(fn ($state, Book $b) => $b->records_verified.' · '.$state.'%')->alignEnd()
                    ->color(fn ($state) => $state === 100 ? 'success' : ($state ? 'warning' : 'gray')),
                TextColumn::make('errors')->label('Помилки')->state(fn (Book $b) => $b->scans_failed + $b->scans_missing)->alignEnd()
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
                TextColumn::make('synced_at')->label('Синхронізовано')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('museum_id')->label('Музей')->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('fund_id')->label('Фонд')->relationship('fund', 'name'),
                SelectFilter::make('book_type')->label('Тип книги')->options(BookType::options()),
                TernaryFilter::make('has_errors')->label('Є помилки')
                    ->queries(true: fn ($query) => $query->where(fn ($w) => $w->where('scans_failed', '>', 0)->orWhere('scans_missing', '>', 0)),
                        false: fn ($query) => $query->where('scans_failed', 0)->where('scans_missing', 0)),
            ])
            ->recordActions([static::reviewAction(), EditAction::make()->label('')])
            ->toolbarActions([
                BulkAction::make('recognize')->label('Розпізнати вибрані книги')->icon('heroicon-o-sparkles')
                    ->visible(fn () => auth()->user()->canOperate())->requiresConfirmation()
                    ->modalDescription('Нерозпізнані та помилкові сторінки вибраних книг буде поставлено в чергу Gemini AI.')
                    ->action(fn (Collection $books) => static::recognize($books)),
            ]);
    }

    /** «Провести звірку» з переліку книг (ТЗ 8.1): перший неперевірений запис книги. */
    public static function reviewAction(): Action
    {
        return Action::make('review')->label('Провести звірку')->icon('heroicon-o-document-magnifying-glass')->color('primary')
            ->visible(fn (Book $record) => $record->records_count > 0)
            ->url(function (Book $record) {
                $r = Record::where('book_id', $record->id)->where('verification_status', '!=', VerificationStatus::Verified->value)->orderBy('sequence')->first()
                    ?? Record::where('book_id', $record->id)->orderBy('sequence')->first();

                return $r ? RecordResource::getUrl('review', ['record' => $r]) : null;
            });
    }

    public static function recognize($books, bool $onlyNew = true): void
    {
        $scans = \App\Models\Scan::whereIn('book_id', collect($books)->pluck('id'))->where('file_status', 'present')
            ->when($onlyNew, fn ($q) => $q->whereIn('recognition_status', ['none', 'failed'])->orWhere(fn ($w) => $w->whereIn('book_id', collect($books)->pluck('id'))->where('needs_recheck', true)))
            ->orderBy('book_id')->orderBy('page_order')->get();
        try {
            [, $n] = app(QueueManager::class)->enqueueRecognition($scans, auth()->user());
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('Розпізнавання не запущено')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($n ? "У черзі {$n} сторінок" : 'Немає сторінок для розпізнавання')
            ->body($n ? 'Обробка триває у фоні; браузер можна закрити.' : null)->send();
    }

    public static function getRelations(): array
    {
        return [ScansRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBooks::route('/'),
            'view' => Pages\ViewBook::route('/{record}'),
            'edit' => Pages\EditBook::route('/{record}/edit'),
            'pages' => Pages\BookPages::route('/{record}/pages'),
        ];
    }
}
