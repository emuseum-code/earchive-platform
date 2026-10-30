<?php

namespace App\Filament\Resources;

use App\Enums\JobType;
use App\Filament\Resources\SyncRunResource\Pages;
use App\Filament\Support\Access;
use App\Models\SyncRun;
use App\Services\QueueManager;
use App\Support\Activity;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Запуски синхронізації сховища та їхні звіти (ТЗ 4.2). */
class SyncRunResource extends Resource
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

    protected static ?string $model = SyncRun::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $modelLabel = 'синхронізація';

    protected static ?string $pluralModelLabel = 'Синхронізація';

    protected static ?int $navigationSort = 4;

    public static function startAction(): Action
    {
        return Action::make('sync')->label('Запустити синхронізацію')->icon('heroicon-o-arrow-path')
            ->visible(fn () => auth()->user()->canOperate())
            ->modalDescription(fn () => 'Коренева директорія: '.(Settings::get('storage.root') ?: 'не задано'))
            ->schema([
                Select::make('scope_path')->label('Область перевірки')->placeholder('Усе сховище')->searchable()
                    ->options(fn () => static::folderOptions())->helperText('Папка відносно кореневої директорії.'),
                Toggle::make('with_checksum')->label('Рахувати контрольні суми (SHA-256)')->helperText('Повільніше, але виявляє заміну файлу з тим самим розміром.'),
            ])
            ->action(function (array $data) {
                $run = SyncRun::create(['user_id' => auth()->id(), 'scope_path' => (string) ($data['scope_path'] ?? ''), 'with_checksum' => (bool) $data['with_checksum'], 'status' => 'queued']);
                Activity::log('sync.started', $run, $run->scope_path ?: 'Усе сховище');
                app(QueueManager::class)->enqueue(JobType::Sync, $run, auth()->user(), priority: 20);
                Notification::make()->success()->title('Синхронізацію запущено')->body('Звіт з’явиться в цьому розділі.')->send();
            });
    }

    /** Папки до третього рівня для вибору області (дерево довантажується лише на цю глибину). */
    public static function folderOptions(): array
    {
        $root = rtrim((string) Settings::get('storage.root'), '/');
        if (! $root || ! is_dir($root)) {
            return [];
        }
        $out = [];
        $walk = function (string $dir, string $rel, int $depth) use (&$walk, &$out) {
            foreach (@scandir($dir) ?: [] as $name) {
                if ($name[0] === '.' || ! is_dir("$dir/$name")) {
                    continue;
                }
                $r = ltrim("$rel/$name", '/');
                $out[$r] = $r;
                if ($depth < 3 && count($out) < 2000) {
                    $walk("$dir/$name", $r, $depth + 1);
                }
            }
        };
        $walk($root, '', 1);

        return $out;
    }

    public static function infolist(Schema $schema): Schema
    {
        $list = fn (string $key, string $label) => TextEntry::make("report.$key")->label($label)->listWithLineBreaks()->limitList(30)->expandableLimitedList()
            ->fontFamily('mono')->size('xs')->placeholder('—');

        return $schema->components([
            Section::make('Звіт')->columnSpanFull()->schema([Grid::make(5)->schema([
                TextEntry::make('started_at')->label('Початок')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('finished_at')->label('Завершено')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                TextEntry::make('scope_path')->label('Область')->placeholder('Усе сховище'),
                TextEntry::make('status')->label('Стан')->badge(),
                TextEntry::make('user.name')->label('Запустив')->placeholder(fn (SyncRun $r) => $r->scheduled ? 'За розкладом' : '—'),
                TextEntry::make('files_found')->label('Знайдено JPG'),
                TextEntry::make('new_books')->label('Нових книг'),
                TextEntry::make('new_scans')->label('Нових сканів'),
                TextEntry::make('changed_scans')->label('Змінених'),
                TextEntry::make('missing_scans')->label('Недоступних')->color(fn ($state) => $state ? 'danger' : null),
                TextEntry::make('restored_scans')->label('Повернулися'),
                TextEntry::make('corrupted_scans')->label('Пошкоджених')->color(fn ($state) => $state ? 'danger' : null),
                TextEntry::make('unmapped_folders')->label('Невизначених папок')->color(fn ($state) => $state ? 'warning' : null),
                TextEntry::make('errors_count')->label('Помилок')->color(fn ($state) => $state ? 'danger' : null),
            ])]),
            Section::make('Деталі')->columnSpanFull()->collapsible()->schema([Grid::make(2)->schema([
                $list('errors', 'Помилки'), $list('unmapped', 'Невизначені папки'),
                $list('new', 'Нові файли'), $list('changed', 'Змінені файли'),
                $list('missing', 'Недоступні файли'), $list('corrupted', 'Пошкоджені файли'),
            ])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->columns([
            TextColumn::make('started_at')->label('Початок')->dateTime('d.m.Y H:i')->placeholder('у черзі'),
            TextColumn::make('scope_path')->label('Область')->placeholder('Усе сховище')->fontFamily('mono')->size('xs'),
            TextColumn::make('status')->label('Стан')->badge(),
            TextColumn::make('new_scans')->label('Нових')->alignEnd(),
            TextColumn::make('changed_scans')->label('Змінених')->alignEnd(),
            TextColumn::make('missing_scans')->label('Недоступних')->alignEnd()->color(fn ($state) => $state ? 'danger' : null),
            TextColumn::make('unmapped_folders')->label('Невизначених')->alignEnd()->color(fn ($state) => $state ? 'warning' : null),
            TextColumn::make('errors_count')->label('Помилок')->alignEnd()->color(fn ($state) => $state ? 'danger' : null),
            TextColumn::make('user.name')->label('Запустив')->placeholder(fn (SyncRun $r) => $r->scheduled ? 'За розкладом' : 'Консоль'),
        ])->recordActions([ViewAction::make()->label('Звіт')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
            'view' => Pages\ViewSyncRun::route('/{record}'),
        ];
    }
}
