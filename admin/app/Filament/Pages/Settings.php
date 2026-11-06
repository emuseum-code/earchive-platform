<?php

namespace App\Filament\Pages;

use App\Enums\FolderLevel;
use App\Models\FolderRule;
use App\Support\Activity;
use App\Support\Settings as S;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/** Налаштування сховища, правил папок, Gemini AI, лімітів і бюджету; кожне збереження — нова версія (ТЗ 18.1). */
class Settings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Адміністрування';

    protected static ?string $title = 'Налаштування';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $data = Arr::undot(S::all());
        $data['rules'] = FolderRule::orderBy('priority')->get()->map(fn ($r) => $r->only(['depth', 'pattern', 'level', 'priority', 'comment']) + ['level' => $r->level->value])->all();
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $keySet = (bool) S::geminiKey();

        return $schema->statePath('data')->components([Tabs::make()->tabs([
            Tab::make('Сховище')->icon('heroicon-o-server-stack')->schema([
                Grid::make(2)->schema([
                    TextInput::make('storage.root')->label('Коренева директорія сканів')->required()->columnSpan(2)
                        ->helperText(fn ($state) => new HtmlString($state && is_dir($state) && is_readable($state)
                            ? '<span style="color:#15803d">✓ Директорія доступна для читання</span>'
                            : '<span style="color:#b91c1c">Директорія недоступна або не існує на сервері</span>')),
                    TagsInput::make('storage.extensions')->label('Розширення файлів'),
                    Select::make('storage.schedule')->label('Синхронізація за розкладом')
                        ->options(['off' => 'Вимкнено', 'hourly' => 'Щогодини', 'daily' => 'Щодня о 02:00', 'weekly' => 'Щонеділі о 03:00']),
                    Toggle::make('storage.checksum')->label('Рахувати SHA-256 при кожній синхронізації'),
                    TextInput::make('storage.upload_max_px')->label('Зменшувати скани з браузера до, px')->numeric()->minValue(0)
                        ->helperText('Довша сторона зображення. Зменшення відбувається в браузері, тож на сервер іде легший файл. 0 — завантажувати оригінали.'),
                    Toggle::make('storage.allow_create_folders')->label('Фізичне створення папок з адмінки')->disabled()
                        ->helperText('Лише після окремого підтвердження Замовника (ТЗ 4.2).'),
                ]),
            ]),
            Tab::make('Правила папок')->icon('heroicon-o-folder')->schema([
                Placeholder::make('rules_hint')->hiddenLabel()->content('Глибина рахується від кореневої директорії з нуля. Шаблон назви (регулярний вираз) має пріоритет над глибиною. Папка з JPG завжди вважається справою.'),
                Repeater::make('rules')->hiddenLabel()->columns(5)->reorderable(false)->schema([
                    TextInput::make('depth')->label('Глибина')->numeric(),
                    TextInput::make('pattern')->label('Шаблон назви'),
                    Select::make('level')->label('Рівень')->options(FolderLevel::options())->required(),
                    TextInput::make('priority')->label('Пріоритет')->numeric()->default(100),
                    TextInput::make('comment')->label('Коментар'),
                ]),
            ]),
            Tab::make('Gemini AI')->icon('heroicon-o-sparkles')->schema([
                Placeholder::make('key')->label('Ключ API')->content(new HtmlString($keySet
                    ? '<span style="color:#15803d">✓ GEMINI_API_KEY задано на сервері</span>'
                    : '<span style="color:#b91c1c">GEMINI_API_KEY не задано.</span> Ключ додається лише в .env на сервері, не в адмінці.')),
                Grid::make(3)->schema([
                    TextInput::make('ai.model')->label('Модель розпізнавання')->required()->datalist(fn () => $this->geminiModels())
                        ->helperText('Якщо Google вимкне модель, система сама перейде на найновішу Pro.')
                        ->suffixAction(\Filament\Actions\Action::make('latestModel')->icon('heroicon-s-sparkles')->tooltip('Найновіша Pro від Google')
                            ->action(fn ($set) => ($m = app(\App\Services\GeminiClient::class)->bestModel('pro')) ? $set('ai.model', $m) : null)),
                    TextInput::make('ai.check_model')->label('Модель «Перевірити AI»')->required()->datalist(fn () => $this->geminiModels())
                        ->suffixAction(\Filament\Actions\Action::make('latestCheckModel')->icon('heroicon-s-sparkles')->tooltip('Найновіша Pro від Google')
                            ->action(fn ($set) => ($m = app(\App\Services\GeminiClient::class)->bestModel('pro')) ? $set('ai.check_model', $m) : null)),
                    TextInput::make('ai.temperature')->label('Температура')->numeric()->step(0.05)->minValue(0)->maxValue(1),
                    TextInput::make('ai.max_output_tokens')->label('Макс. токенів відповіді')->numeric(),
                    TextInput::make('ai.timeout')->label('Тайм-аут запиту, с')->numeric(),
                    TextInput::make('ai.max_attempts')->label('Автоматичних повторів')->numeric()->minValue(1)->maxValue(10),
                    TextInput::make('ai.requests_per_minute')->label('Ліміт запитів за хвилину')->numeric(),
                    TextInput::make('ai.concurrency')->label('Паралельних воркерів')->numeric()
                        ->helperText('Застосовується при перезапуску воркерів черги.'),
                    TextInput::make('ai.monthly_budget_usd')->label('Місячний бюджет, $')->numeric()->helperText('0 — без обмеження.'),
                    TextInput::make('ai.price_input_per_m')->label('Ціна вхідних токенів, $ за 1 млн')->numeric(),
                    TextInput::make('ai.price_output_per_m')->label('Ціна вихідних токенів, $ за 1 млн')->numeric(),
                ]),
                Toggle::make('ai.mass_processing_allowed')->label('Дозволити масову обробку')
                    ->helperText('Вмикайте після успішного тестового розпізнавання (ТЗ 18.1 п.6).'),
                TextInput::make('ai.test_scan_limit')->label('Сторінок за один запуск без масової обробки')->numeric()->minValue(1)->required()
                    ->helperText('Поки масову обробку не дозволено, більші запуски блокуються.'),
                Toggle::make('ai.demo_mode')->label('Демо-режим без ключа')
                    ->helperText('Лише для показу: «Перевірити AI» бере пропозиції із зауважень, повторне розпізнавання повторює попередній результат.'),
            ]),
            Tab::make('Реєстр')->icon('heroicon-o-building-library')->schema([
                TextInput::make('registry.api_url')->label('Адреса API реєстру')->url()
                    ->helperText('Пряме передавання — після погодження обсягу інтеграції та надання документації (ТЗ 11).'),
            ]),
            Tab::make('Резервні копії')->icon('heroicon-o-circle-stack')->schema([
                Placeholder::make('backups')->hiddenLabel()->content(fn () => new HtmlString(view('filament.pages.backups', [
                    'last' => \App\Support\Backups::last(), 'files' => \App\Support\Backups::files(), 'healthy' => \App\Support\Backups::healthy(),
                ])->render())),
            ]),
        ])]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $rules = $state['rules'] ?? [];
        unset($state['rules']);
        S::setMany($state);
        FolderRule::query()->delete();
        foreach ($rules as $r) {
            FolderRule::create(['depth' => $r['depth'] === '' ? null : $r['depth'], 'pattern' => $r['pattern'] ?: null, 'level' => $r['level'],
                'priority' => $r['priority'] ?: 100, 'comment' => $r['comment'] ?? null]);
        }
        Activity::log('settings.updated', null, 'Збережено налаштування', ['keys' => array_keys(Arr::dot($state))]);
        Notification::make()->success()->title('Налаштування збережено')->send();
    }

    /** Моделі, доступні з цим ключем (кеш 1 год). */
    public function geminiModels(): array
    {
        if (! S::geminiKey()) {
            return [];
        }

        return cache()->remember('gemini.models', 3600, fn () => app(\App\Services\GeminiClient::class)->availableModels());
    }
}
