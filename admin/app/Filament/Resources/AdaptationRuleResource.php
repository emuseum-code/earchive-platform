<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdaptationRuleResource\Pages;
use App\Filament\Support\Access;
use App\Models\AdaptationRule;
use App\Models\Record;
use App\Models\RegistryField;
use App\Models\RegistrySchema;
use App\Support\Activity;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Таблиця відповідностей поле книги → поле реєстру (ТЗ 9). Кожна зміна правила — нова версія. */
class AdaptationRuleResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'staff';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = AdaptationRule::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Адаптація й експорт';

    protected static ?string $modelLabel = 'правило адаптації';

    protected static ?string $pluralModelLabel = 'Правила адаптації';

    protected static ?int $navigationSort = 4;

    public static function sourceOptions(): array
    {
        return collect(Record::FIELDS)->map(fn ($f) => $f[0])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columnSpanFull()->columns(2)->schema([
            Select::make('registry_schema_id')->label('Схема')->options(fn () => RegistrySchema::pluck('name', 'id'))->required()->live()
                ->default(fn () => RegistrySchema::active()?->id),
            Select::make('target_field')->label('Поле реєстру')->required()->searchable()
                ->options(fn (Get $get) => RegistryField::where('registry_schema_id', $get('registry_schema_id'))->orderBy('sort')->pluck('label', 'key')),
            Select::make('source_fields')->label('Поля книги')->multiple()->options(self::sourceOptions()),
            Select::make('transform')->label('Перетворення')->options(AdaptationRule::TRANSFORMS)->required()->default('copy'),
            KeyValue::make('params')->label('Параметри')->keyLabel('Параметр')->valueLabel('Значення')
                ->helperText('dictionary: код довідника; pattern: регулярний вираз; value: стала')->columnSpanFull(),
            Toggle::make('use_ai')->label('Змістовий розбір Gemini AI')->inline(false),
            Toggle::make('is_active')->label('Діє')->default(true)->inline(false),
            Textarea::make('comment')->label('Пояснення для AI та працівників')->rows(2)->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        $labels = fn () => once(fn () => RegistryField::pluck('label', 'key')->all());

        return $table->defaultSort('sort')->reorderable('sort')->paginated([50, 100])->columns([
            TextColumn::make('target_field')->label('Поле реєстру')->formatStateUsing(fn ($state) => $labels()[$state] ?? $state)->wrap()->weight('medium'),
            TextColumn::make('source_fields')->label('Поля книги')->formatStateUsing(fn ($state) => Record::label($state))->badge()->color('gray'),
            TextColumn::make('transform')->label('Перетворення')->formatStateUsing(fn ($state) => AdaptationRule::TRANSFORMS[$state] ?? $state)->wrap(),
            IconColumn::make('use_ai')->label('AI')->boolean()->trueIcon('heroicon-o-sparkles'),
            TextColumn::make('version')->label('Версія')->alignEnd(),
            IconColumn::make('is_active')->label('Діє')->boolean(),
        ])->filters([TernaryFilter::make('use_ai')->label('Gemini AI')])
            ->recordActions([EditAction::make()->label('')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdaptationRules::route('/'),
            'create' => Pages\CreateAdaptationRule::route('/create'),
            'edit' => Pages\EditAdaptationRule::route('/{record}/edit'),
        ];
    }

    public static function logSaved(AdaptationRule $r): void
    {
        Activity::log('rule.updated', $r, $r->target_field.' v'.$r->version);
    }
}
