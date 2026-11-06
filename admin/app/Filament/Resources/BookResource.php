<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Enums\VerificationStatus;
use App\Filament\Resources\BookResource\Pages;
use App\Filament\Resources\BookResource\RelationManagers\ScansRelationManager;
use App\Filament\Support\Access;
use App\Filament\Support\Ai;
use App\Models\Book;
use App\Models\Fund;
use App\Models\Museum;
use App\Models\RecognitionTemplate;
use App\Models\Record;
use App\Models\Scan;
use App\Services\QueueManager;
use App\Services\StorageSync;
use App\Support\Activity;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/** Справи та їхні картки (ТЗ 4.1, 10). */
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

    protected static ?string $modelLabel = 'справа';

    protected static ?string $pluralModelLabel = 'Справи';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'folder_path'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['Архів' => $record->museum?->name, 'Фонд' => $record->fund?->name]);
    }

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['museum', 'fund', 'template']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columnSpanFull()->schema([Grid::make(3)->schema([
            TextInput::make('title')->label('Назва справи')->required()->columnSpan(2),
            TextInput::make('inventory_no')->label('Опис'),
            TextInput::make('book_number')->label('№ справи'),
            Select::make('museum_id')->label('Архів')->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                ->required()->searchable()->live(),
            Select::make('fund_id')->label('Фонд')->options(fn (Get $get) => Fund::where('museum_id', $get('museum_id'))->pluck('name', 'id'))
                ->helperText('Після збереження всі документи справи перейдуть у цей фонд (і архів).'),
            Select::make('book_type')->label('Тип справи')->options(BookType::options())->required(),
            Select::make('recognition_template_id')->label('Шаблон розпізнавання')->options(fn () => RecognitionTemplate::where('is_active', true)->pluck('name', 'id'))
                ->helperText('Порожньо — за типом справи.'),
            TextInput::make('years')->label('Крайні дати'),
            TextInput::make('language')->label('Мова документів'),
            TextInput::make('expected_pages')->label('Очікувана кількість сторінок')->numeric()
                ->helperText('Без неї наявність сканів не означає повноти справи.'),
            TextInput::make('folder_path')->label('Папка у сховищі')->disabled()->columnSpan(2),
            Textarea::make('notes')->label('Примітки')->rows(2)->columnSpanFull(),
        ])])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([Grid::make(4)->schema([
                TextEntry::make('museum.name')->label('Архів')->columnSpan(2),
                TextEntry::make('fund.name')->label('Фонд')->placeholder('—'),
                TextEntry::make('code')->label('Архівний шифр')->state(fn (Book $b) => $b->archiveCode())->placeholder('—'),
                TextEntry::make('book_type')->label('Тип справи')->badge(),
                TextEntry::make('folder_path')->label('Шлях до папки')->fontFamily('mono')->size('xs')->columnSpan(2)->copyable(),
                TextEntry::make('uid')->label('Ідентифікатор')->fontFamily('mono')->size('xs')->copyable(),
                TextEntry::make('template.name')->label('Шаблон розпізнавання')->placeholder('За типом справи')
                    ->suffix(fn (Book $b) => $b->template ? ' · v'.$b->template->version : ''),
                TextEntry::make('scans_count')->label('Сканів')
                    ->suffix(fn (Book $b) => $b->expected_pages ? ' з '.$b->expected_pages.' очікуваних' : ' (очікувана кількість невідома)'),
                TextEntry::make('recognition')->label('Розпізнавання')->state(fn (Book $b) => $b->recognitionProgress().'%')
                    ->helperText(fn (Book $b) => $b->scans_recognized.' сторінок розпізнано'),
                TextEntry::make('records_count')->label('Отримано документів')->helperText(fn (Book $b) => $b->records_verified.' перевірено · '.$b->records_adapted.' адаптовано'),
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
                TextColumn::make('title')->label('Справа')->searchable()->sortable()->weight('medium')->wrap()
                    ->description(fn (Book $b) => $b->museum?->name),
                TextColumn::make('fund.name')->label('Фонд')->toggleable()->placeholder('—'),
                TextColumn::make('code')->label('Шифр')->state(fn (Book $b) => $b->archiveCode())->placeholder('—')->toggleable(),
                TextColumn::make('book_type')->label('Тип')->badge()->toggleable(),
                TextColumn::make('scans_count')->label('Сканів')->sortable()->alignEnd(),
                TextColumn::make('recognition')->label('Розпізнано')->state(fn (Book $b) => $b->recognitionProgress())
                    ->formatStateUsing(fn ($state) => $state.'%')->alignEnd()
                    ->color(fn ($state) => $state === 100 ? 'success' : ($state ? 'warning' : 'gray')),
                TextColumn::make('records_count')->label('Документів')->sortable()->alignEnd(),
                TextColumn::make('verification')->label('Перевірено')->state(fn (Book $b) => $b->verificationProgress())
                    ->formatStateUsing(fn ($state, Book $b) => $b->records_verified.' · '.$state.'%')->alignEnd()
                    ->color(fn ($state) => $state === 100 ? 'success' : ($state ? 'warning' : 'gray')),
                TextColumn::make('errors')->label('Помилки')->state(fn (Book $b) => $b->scans_failed + $b->scans_missing)->alignEnd()
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
                TextColumn::make('synced_at')->label('Синхронізовано')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('museum_id')->label('Архів')->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('fund_id')->label('Фонд')->relationship('fund', 'name'),
                SelectFilter::make('book_type')->label('Тип справи')->options(BookType::options()),
                TernaryFilter::make('has_errors')->label('Є помилки')
                    ->queries(true: fn ($query) => $query->where(fn ($w) => $w->where('scans_failed', '>', 0)->orWhere('scans_missing', '>', 0)),
                        false: fn ($query) => $query->where('scans_failed', 0)->where('scans_missing', 0)),
            ])
            ->recordActions([static::reviewAction(), EditAction::make()->label('')])
            ->toolbarActions([
                Ai::item(BulkAction::make('recognize')->label('Розпізнати вибрані справи'))
                    ->visible(fn () => auth()->user()->canOperate())->requiresConfirmation()
                    ->modalDescription('Нерозпізнані та помилкові сторінки вибраних справ буде поставлено в чергу Gemini AI.')
                    ->action(fn (Collection $books) => static::recognize($books)),
            ]);
    }

    /** «Провести звірку» з переліку справ (ТЗ 8.1): перший неперевірений документ справи. */
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

    /**
     * Завантаження сканів з браузера (сховище на зовнішніх дисках, як у пілоті Лисичанська).
     * Файли кладуться в папку справи в кореневій директорії й одразу реєструються як сторінки, без синхронізації.
     * Браузер зменшує зображення до storage.upload_max_px (довша сторона) ще до відправлення на сервер.
     * $book = null: нова справа (архів, фонд, назва); $museumId — архів, вибраний наперед (сторінка «Робота з архівом»).
     */
    public static function uploadAction(?Book $book = null, ?int $museumId = null): Action
    {
        $new = $book === null;

        return Action::make($new ? 'uploadNewBook' : 'uploadScans')
            ->label($new ? 'Нова справа зі сканів' : 'Завантажити скани')
            ->icon('heroicon-o-arrow-up-tray')
            ->color($new ? 'primary' : 'gray')
            ->visible(fn () => auth()->user()->canOperate())
            ->modalHeading($new ? 'Нова справа: завантажити скани' : 'Завантажити скани в справу')
            ->modalDescription(fn () => 'JPG, PNG або TIFF, до 50 МБ кожен. Порядок сторінок: «обложка» перша, «посл.стр.» остання, решта за назвою файлу (IMG_0001, IMG_0002…).'
                .(static::uploadMaxPx() ? ' JPG і PNG зменшуються в браузері до '.static::uploadMaxPx().' px по довшій стороні.' : ''))
            ->modalSubmitActionLabel('Завантажити')
            ->schema(array_filter([
                $new ? Select::make('museum_id')->label('Архів')->required()->searchable()->preload()->live()->default($museumId)
                    ->options(fn () => Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')) : null,
                $new ? Select::make('fund_id')->label('Фонд / група збереження')->searchable()
                    ->helperText('Перевірте фонд: це справа, в якій предмети облікові. Усі документи справи отримають саме цей фонд, незалежно від матеріалу предметів.')
                    ->options(fn (Get $get) => Fund::where('museum_id', $get('museum_id'))->orderBy('name')->pluck('name', 'id')) : null,
                $new ? TextInput::make('title')->label('Назва справи')->required()->maxLength(200)
                    ->placeholder('Справа Д-ІІІ (документи)') : null,
                FileUpload::make('files')->label('Скани')->required()->multiple()->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/tiff'])->maxSize(51200)->maxFiles(500)
                    ->panelLayout('grid')->reorderable(false)->previewable(false)
                    ->imageResizeMode(fn () => static::uploadMaxPx() ? 'contain' : null)
                    ->imageResizeTargetWidth(fn () => static::uploadMaxPx() ?: null)
                    ->imageResizeTargetHeight(fn () => static::uploadMaxPx() ?: null)
                    ->imageResizeUpscale(false),
            ]))
            ->action(function (array $data) use ($book) {
                $root = rtrim((string) Settings::get('storage.root'), '/');
                if ($root === '' || ! is_dir($root) || ! is_writable($root)) {
                    Notification::make()->danger()->title('Сховище недоступне для документа')->body($root ?: 'Коренева директорія не задана')->send();

                    return;
                }
                $book ??= static::createUploadedBook($data);
                $dir = $root.'/'.$book->folder_path;
                File::ensureDirectoryExists($dir);
                $saved = 0;
                $names = [];
                foreach ((array) $data['files'] as $file) {
                    $name = preg_replace('~[/\\\\\x00]~u', '_', basename($file->getClientOriginalName())) ?: 'scan.jpg';
                    $target = $dir.'/'.$name;
                    for ($i = 2; file_exists($target) && md5_file($target) !== md5_file($file->getRealPath()); $i++) {
                        $target = $dir.'/'.pathinfo($name, PATHINFO_FILENAME)."-{$i}.".pathinfo($name, PATHINFO_EXTENSION);
                    }
                    if (! file_exists($target)) {
                        copy($file->getRealPath(), $target);
                        $saved++;
                    }
                    $names[] = basename($target);
                }
                // без синхронізації: реєструються лише щойно завантажені файли цієї справи
                [$new, $errors] = app(StorageSync::class)->registerUploaded($book, array_values(array_unique($names)));
                Activity::log('scans.uploaded', $book, "Завантажено сканів: {$saved}, нових сторінок: {$new}");
                Notification::make()->{$errors ? 'warning' : 'success'}()->title("Завантажено сканів: {$saved}")
                    ->body("Нових сторінок у справі: {$new}. Тепер їх можна розпізнати (Обробка → Розпізнати)."
                        .($errors ? ' Помилки: '.implode('; ', array_slice($errors, 0, 3)) : ''))->send();

                return redirect(static::getUrl('view', ['record' => $book]));
            });
    }

    /** Довша сторона скану після зменшення в браузері; 0 — без зменшення. */
    public static function uploadMaxPx(): int
    {
        return max(0, (int) Settings::get('storage.upload_max_px'));
    }

    private static function createUploadedBook(array $data): Book
    {
        $museum = Museum::findOrFail($data['museum_id']);
        $fund = ! empty($data['fund_id']) ? Fund::find($data['fund_id']) : null;
        $base = $museum->folder_path ?: 'Завантажено/'.$museum->name;
        $base = $fund ? ($fund->folder_path ?: $base.'/'.$fund->name) : $base;
        $slug = trim(preg_replace('~[/\\\\:*?"<>|]+~u', ' ', $data['title']));
        $path = $base.'/'.$slug;
        for ($i = 2; Book::withTrashed()->where('folder_path', $path)->exists(); $i++) {
            $path = $base.'/'.$slug." ({$i})";
        }

        return Book::create(['museum_id' => $museum->id, 'fund_id' => $fund?->id, 'title' => $data['title'], 'folder_path' => $path]);
    }

    public static function recognize($books, bool $onlyNew = true): void
    {
        $scans = Scan::whereIn('book_id', collect($books)->pluck('id'))->where('file_status', 'present')
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
