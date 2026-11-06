<?php

namespace App\Filament\Resources\BookResource\RelationManagers;

use App\Enums\FileStatus;
use App\Enums\RecognitionStatus;
use App\Filament\Resources\BookResource;
use App\Models\Scan;
use App\Services\QueueManager;
use App\Support\Activity;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Сторінки справи з порядком, статусами й запуском розпізнавання (ТЗ 4.1, 4.3, 6.1). */
class ScansRelationManager extends RelationManager
{
    protected static string $relationship = 'scans';

    protected static ?string $title = 'Сторінки (скани)';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $canOperate = fn () => auth()->user()->canOperate();

        return $table
            ->defaultSort('page_order')
            ->reorderable('page_order', $canOperate())
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('page_order')->label('№')->alignEnd()->width('3rem'),
                ImageColumn::make('thumb')->label('')->state(fn (Scan $s) => $s->file_status === FileStatus::Present ? $s->thumbUrl() : null)
                    ->imageHeight(64)->extraImgAttributes(['loading' => 'lazy', 'class' => 'rounded border']),
                TextColumn::make('filename')->label('Файл')->searchable()->fontFamily('mono')->size('xs')
                    ->description(fn ($record) => ['cover' => 'Обкладинка: не розпізнається', 'last' => 'Остання сторінка (засвідчувальний напис)'][$record->page_kind] ?? null)
                    ->description(fn (Scan $s) => $s->page_label),
                TextColumn::make('file_status')->label('Файл')->badge(),
                TextColumn::make('recognition_status')->label('Розпізнавання')->badge()
                    ->description(fn (Scan $s) => $s->needs_recheck ? 'Скан змінився — потрібна перевірка' : ($s->error ? mb_strimwidth($s->error, 0, 80, '…') : null)),
                TextColumn::make('records_count')->label('Документів')->counts('records')->alignEnd(),
                TextColumn::make('file_size')->label('Розмір')->formatStateUsing(fn ($state) => $state ? number_format($state / 1048576, 1).' МБ' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dims')->label('Пікселі')->state(fn (Scan $s) => $s->width ? $s->width.'×'.$s->height : '—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('recognition_status')->label('Розпізнавання')->options(RecognitionStatus::options()),
                SelectFilter::make('file_status')->label('Файл')->options(FileStatus::options()),
                TernaryFilter::make('needs_recheck')->label('Потребує повторної перевірки'),
            ])
            ->recordActions([
                Action::make('open')->label('Відкрити')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (Scan $s) => BookResource::getUrl('pages', ['record' => $this->getOwnerRecord(), 'scan' => $s->id])),
                \App\Filament\Support\Ai::button(Action::make('recognize')->label('Розпізнати'))->visible($canOperate)
                    ->hidden(fn (Scan $s) => $s->file_status !== FileStatus::Present)
                    ->action(function (Scan $s) {
                        app(QueueManager::class)->enqueueRecognition([$s], auth()->user());
                        Notification::make()->success()->title('Сторінку поставлено в чергу')->send();
                    }),
            ])
            ->toolbarActions([
                \App\Filament\Support\Ai::item(BulkAction::make('recognize')->label('Розпізнати вибрані'))->visible($canOperate)
                    ->action(function (Collection $scans) {
                        try {
                            [, $n] = app(QueueManager::class)->enqueueRecognition($scans, auth()->user());
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('Розпізнавання не запущено')->body($e->getMessage())->persistent()->send();

                            return;
                        }
                        Notification::make()->success()->title("У черзі {$n} сторінок")->send();
                    }),
            ]);
    }

    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);
        Activity::log('scan.reordered', $this->getOwnerRecord(), 'Змінено порядок сторінок');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
