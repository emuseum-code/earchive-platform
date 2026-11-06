<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Filament\Resources\RecognitionTemplateResource\Pages;
use App\Filament\Support\Access;
use App\Models\Record;
use App\Models\RecognitionTemplate;
use App\Models\Scan;
use App\Services\QueueManager;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Шаблони розпізнавання для типів справ (ТЗ 6.2). Зміна промпту чи граф — нова версія. */
class RecognitionTemplateResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'operate';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = RecognitionTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Розпізнавання';

    protected static ?string $modelLabel = 'шаблон розпізнавання';

    protected static ?string $pluralModelLabel = 'Шаблони розпізнавання';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([Grid::make(4)->schema([
                TextInput::make('name')->label('Назва')->required()->columnSpan(2),
                Select::make('book_type')->label('Тип справи')->options(BookType::options())->required(),
                Toggle::make('is_active')->label('Діє')->default(true)->inline(false),
                Textarea::make('description')->label('Опис')->rows(2)->columnSpanFull(),
            ])]),
            Section::make('Графи справи')->description('Те, що не зіставлено з полем єдиної таблиці, зберігається в додаткових полях з назвою графи.')
                ->columnSpanFull()->schema([
                    Repeater::make('columns')->hiddenLabel()->reorderable()->columns(4)->defaultItems(1)->schema([
                        TextInput::make('column_no')->label('№ графи')->numeric(),
                        TextInput::make('key')->label('Ключ')->required()->alphaDash(),
                        TextInput::make('label')->label('Назва графи в справі')->required(),
                        Select::make('maps_to')->label('Поле єдиної таблиці')->options(collect(Record::FIELDS)->map(fn ($f) => $f[0]))
                            ->placeholder('Додаткове поле'),
                    ])->itemLabel(fn (array $state) => ($state['column_no'] ?? '').' · '.($state['label'] ?? '')),
                ]),
            Section::make('Інструкція для Gemini AI')->columnSpanFull()->schema([
                Textarea::make('prompt')->hiddenLabel()->rows(8)->required()
                    ->helperText('Загальні правила (дослівно, без вигадок, продовження документів, координати) додаються автоматично.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Шаблон')->weight('medium')->description(fn ($record) => $record->description)->wrap(),
            TextColumn::make('book_type')->label('Тип справи')->badge(),
            TextColumn::make('columns')->label('Граф')->state(fn ($record) => count($record->columns ?? []))->alignEnd(),
            TextColumn::make('version')->label('Версія')->alignEnd(),
            TextColumn::make('books_count')->label('Справ')->counts('books')->alignEnd(),
            IconColumn::make('is_active')->label('Діє')->boolean(),
            TextColumn::make('updated_at')->label('Змінено')->since(),
        ])->recordActions([
            Action::make('test')->label('Тест')->icon('heroicon-o-play')->color('gray')
                ->modalHeading('Тестове розпізнавання')->modalDescription('Сторінку буде поставлено в чергу з цим шаблоном. Результат з’явиться в справі.')
                ->schema([Select::make('scan_id')->label('Скан')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $s) => Scan::visibleTo(auth()->user())->where('path', 'like', "%{$s}%")->limit(30)->pluck('path', 'id'))
                    ->getOptionLabelUsing(fn ($v) => Scan::find($v)?->path)])
                ->action(function (RecognitionTemplate $record, array $data) {
                    $scan = Scan::findOrFail($data['scan_id']);
                    $scan->book->update(['recognition_template_id' => $record->id]);
                    app(QueueManager::class)->enqueueRecognition([$scan], auth()->user());
                    Notification::make()->success()->title('Сторінку поставлено в чергу')->send();
                }),
            EditAction::make()->label(''),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecognitionTemplates::route('/'),
            'create' => Pages\CreateRecognitionTemplate::route('/create'),
            'edit' => Pages\EditRecognitionTemplate::route('/{record}/edit'),
        ];
    }
}
