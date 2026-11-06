<?php

namespace App\Filament\Resources\RecordResource\Pages;

use App\Enums\VersionSource;
use App\Exceptions\StaleRecordException;
use App\Filament\Resources\RecordResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Редагування документа з перевіркою версії: чужі зміни не перезаписуються непомітно (ТЗ 5). */
class EditRecordData extends EditRecord
{
    protected static string $resource = RecordResource::class;

    public int $lockVersion = 0;

    protected function afterFill(): void
    {
        $this->lockVersion = $this->record->lock_version;
    }

    public function getTitle(): string
    {
        return 'Документ № '.($this->record->record_no ?: $this->record->id);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $record->applyChanges($data, VersionSource::Manual, auth()->user(), $this->lockVersion);
        } catch (StaleRecordException $e) {
            Notification::make()->danger()->title('Конфлікт редагування')->body($e->getMessage())->persistent()->send();
            throw new Halt;
        }
        $this->lockVersion = $record->lock_version;

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return RecordResource::getUrl('view', ['record' => $this->record]);
    }
}
