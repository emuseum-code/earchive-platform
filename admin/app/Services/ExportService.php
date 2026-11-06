<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\JobStatus;
use App\Models\Export;
use App\Models\Record;
use App\Models\RegistrySchema;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Експорт вибраних або відфільтрованих документів у CSV, XLSX, JSON і шаблон імпорту РМФУ (ТЗ 11).
 * Фіксує користувача, час, склад і версії документів. Створений файл — не підтвердження приймання реєстром.
 */
class ExportService
{
    /** Документи для експорту: ids або фільтри з єдиної таблиці; лише в межах доступу автора. */
    public function query(Export $export): Builder
    {
        $f = $export->filters ?? [];
        $q = Record::query()->visibleTo($export->user)->with(['book', 'museum', 'fund', 'scans', 'currentAdaptation']);
        if (! empty($f['ids'])) {
            $q->whereIn('id', $f['ids']);
        }
        foreach (['museum_id', 'book_id', 'fund_id', 'verification_status', 'adaptation_status', 'export_status'] as $k) {
            if (! empty($f[$k])) {
                $q->whereIn($k, (array) $f[$k]);
            }
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('description', 'like', $s)->orWhere('inventory_number', 'like', $s)->orWhere('account_number', 'like', $s));
        }

        return $q->orderBy('book_id')->orderBy('sequence');
    }

    public function build(Export $export): Export
    {
        $export->update(['status' => JobStatus::Running]);
        try {
            $registry = $export->format === ExportFormat::Registry;
            $schema = $registry ? (RegistrySchema::find($export->registry_schema_id) ?? RegistrySchema::active()) : null;
            $records = $this->query($export)->get();
            if ($registry) {
                // у реєстр — лише перевірені документи з актуальною адаптацією без помилок (ТЗ 9)
                $skipped = $records->reject(fn ($r) => $r->isExportReady())->count();
                $records = $records->filter(fn ($r) => $r->isExportReady())->values();
                if ($records->isEmpty()) {
                    throw new \DomainException('Немає документів, готових до експорту в реєстр'.($skipped ? " (пропущено неготових: {$skipped})" : '').'.');
                }
            }
            [$header, $rows] = $registry ? $this->registryRows($records, $schema) : $this->sourceRows($records);
            $ext = match ($export->format) { ExportFormat::Csv => 'csv', ExportFormat::Json => 'json', default => 'xlsx' };
            $path = 'exports/'.now()->format('Y/m').'/'.($registry ? 'rmfu' : 'records').'-'.now()->format('Ymd-His').'-'.substr($export->uid, 0, 8).'.'.$ext;
            $abs = Storage::disk('local')->path($path);
            @mkdir(dirname($abs), 0775, true);
            match ($ext) {
                'json' => file_put_contents($abs, json_encode(['exported_at' => now()->toIso8601String(), 'schema' => $schema?->only(['name', 'version']),
                    'records' => collect($rows)->map(fn ($r) => array_combine($header, $r))->all()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
                'csv' => $this->writeSheet(new CsvWriter, $abs, $header, $rows),
                default => $this->writeSheet(new XlsxWriter, $abs, $header, $rows),
            };
            foreach ($records as $r) {
                $export->items()->create([
                    'record_id' => $r->id, 'record_version' => $r->lock_version,
                    'adaptation_id' => $r->currentAdaptation?->id, 'adaptation_version' => $r->currentAdaptation?->version,
                ]);
                if ($registry) {
                    $r->update(['export_status' => ExportStatus::Exported]);
                }
            }
            $export->update(['status' => JobStatus::Done, 'file_path' => $path, 'file_size' => filesize($abs), 'records_count' => $records->count(),
                'registry_schema_id' => $schema?->id, 'finished_at' => now()]);
            Activity::log('export.created', $export, $export->format->getLabel().': документів '.$records->count(), [], $export->user);
        } catch (\Throwable $e) {
            $export->update(['status' => JobStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);
            if (! $e instanceof \DomainException) {
                report($e);
            }
        }

        return $export;
    }

    private function writeSheet($writer, string $abs, array $header, array $rows): void
    {
        $writer->openToFile($abs);
        $writer->addRow(Row::fromValues($header, (new Style)->setFontBold()));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $r)));
        }
        $writer->close();
    }

    private function sourceRows($records): array
    {
        $header = array_merge(['ID', 'Архів', 'Фонд', 'Справа', 'Сторінки'], array_map(fn ($f) => $f[0], Record::FIELDS),
            ['Додаткові поля', 'Звірка', 'Адаптація', 'Версія документа', 'Перевірив', 'Дата перевірки']);
        $rows = $records->map(fn (Record $r) => array_merge(
            [$r->uid, $r->museum?->name, $r->fund?->name, $r->book?->title, $r->pageLabel()],
            array_map(fn ($f) => $r->$f, array_keys(Record::FIELDS)),
            [$r->extra_fields ? json_encode($r->extra_fields, JSON_UNESCAPED_UNICODE) : null, $r->verification_status->getLabel(),
                $r->adaptation_status->getLabel(), $r->lock_version, $r->verifier?->name, \App\Support\Tz::fmt($r->verified_at, 'Y-m-d H:i')]
        ))->all();

        return [$header, $rows];
    }

    /** Колонки в порядку шаблону міграції даних РМФУ. */
    private function registryRows($records, RegistrySchema $schema): array
    {
        $fields = $schema->fields;
        $header = $fields->map(fn ($f) => ($f->required ? '*' : '').$f->label.($f->api_path ? ' ('.$f->api_path.')' : ''))->all();
        $rows = $records->map(fn (Record $r) => $fields->map(fn ($f) => $r->currentAdaptation->values[$f->key] ?? null)->all())->all();

        return [$header, $rows];
    }
}
