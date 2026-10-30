<?php

namespace App\Filament\Resources;

use App\Enums\FolderLevel;
use App\Enums\JobType;
use App\Filament\Resources\UnmappedFolderResource\Pages;
use App\Filament\Support\Access;
use App\Models\Museum;
use App\Models\SyncRun;
use App\Models\UnmappedFolder;
use App\Services\QueueManager;
use App\Support\Activity;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Папки, які не зіставилися з рівнями дерева, — ручне уточнення (ТЗ 4.1). */
class UnmappedFolderResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'operate';
    }

    protected static function editAccess(): string
    {
        return 'none';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = UnmappedFolder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $modelLabel = 'невизначена папка';

    protected static ?string $pluralModelLabel = 'Невизначені папки';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $n = UnmappedFolder::whereNull('resolution')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('path')->columns([
            TextColumn::make('path')->label('Папка')->fontFamily('mono')->size('xs')->searchable()->wrap(),
            TextColumn::make('jpg_count')->label('JPG')->alignEnd(),
            TextColumn::make('resolution')->label('Рішення')->badge()->placeholder('Не уточнено'),
            TextColumn::make('museum.name')->label('Музей')->placeholder('—'),
            TextColumn::make('book.title')->label('Книга')->placeholder('—')
                ->url(fn (UnmappedFolder $f) => $f->book_id ? BookResource::getUrl('view', ['record' => $f->book_id]) : null),
            TextColumn::make('updated_at')->label('Виявлено')->since(),
        ])->filters([TernaryFilter::make('resolved')->label('Уточнено')->nullable()->attribute('resolution')->default(false)])
            ->recordActions([
                Action::make('resolve')->label('Уточнити')->icon('heroicon-o-pencil-square')
                    ->schema([
                        Radio::make('resolution')->label('Чим є ця папка')->required()->live()->options([
                            FolderLevel::Book->value => 'Книга музею',
                            FolderLevel::Skip->value => 'Не обліковувати (службова, дублікат тощо)',
                        ]),
                        Select::make('museum_id')->label('Музей')->options(fn () => Museum::orderBy('name')->pluck('name', 'id'))->searchable()
                            ->required(fn (Get $get) => $get('resolution') === FolderLevel::Book->value)->visible(fn (Get $get) => $get('resolution') === FolderLevel::Book->value)->live(),
                    ])
                    ->action(function (UnmappedFolder $record, array $data) {
                        $record->update(['resolution' => $data['resolution'], 'museum_id' => $data['museum_id'] ?? null, 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
                        Activity::log('folder.resolved', $record, $record->path.' → '.$record->resolution->getLabel());
                        if ($record->resolution === FolderLevel::Book) {
                            $run = SyncRun::create(['user_id' => auth()->id(), 'scope_path' => $record->path, 'status' => 'queued']);
                            app(QueueManager::class)->enqueue(JobType::Sync, $run, auth()->user(), priority: 20);
                            Notification::make()->success()->title('Папку віднесено до музею; синхронізацію запущено')->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUnmappedFolders::route('/')];
    }
}
