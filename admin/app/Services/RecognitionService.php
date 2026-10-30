<?php

namespace App\Services;

use App\Enums\AiCheckStatus;
use App\Enums\RecognitionStatus;
use App\Enums\VerificationStatus;
use App\Enums\VersionSource;
use App\Models\AiCheck;
use App\Models\ProcessingJob;
use App\Models\RecognitionResult;
use App\Models\RecognitionTemplate;
use App\Models\Record;
use App\Models\RecordPage;
use App\Models\Scan;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Розпізнавання сторінки через Gemini AI (ТЗ 6). Зберігає сиру відповідь, версію моделі й шаблону,
 * перевіряє відповідь за схемою, створює або оновлює записи єдиної таблиці.
 */
class RecognitionService
{
    public function __construct(private GeminiClient $gemini) {}

    public function recognize(Scan $scan, ?ProcessingJob $job = null): RecognitionResult
    {
        $book = $scan->book;
        $template = $book->template ?? RecognitionTemplate::where('is_active', true)->where('book_type', $book->book_type)->first()
            ?? RecognitionTemplate::where('is_active', true)->firstOrFail();
        $scan->update(['recognition_status' => RecognitionStatus::Processing, 'error' => null]);
        $started = microtime(true);

        if (Settings::aiDemo()) {
            $out = $this->demoResponse($scan);
        } else {
            $prev = $this->previousPageTail($scan);
            $parts = [['text' => $this->prompt($template, $prev)], GeminiClient::imagePart($scan->absolutePath())];
            $out = $this->gemini->generateJson('recognize', $parts, $this->schema($template), Settings::get('ai.model'), $job?->id, $job?->user_id);
        }
        [$parsed, $errors] = $this->validate($out['json'], $template);

        return DB::transaction(function () use ($scan, $book, $template, $job, $out, $parsed, $errors, $started) {
            $result = RecognitionResult::create([
                'scan_id' => $scan->id,
                'book_id' => $book->id,
                'processing_job_id' => $job?->id,
                'recognition_template_id' => $template->id,
                'template_version' => $template->version,
                'version' => (int) RecognitionResult::where('scan_id', $scan->id)->max('version') + 1,
                'model' => $out['model'],
                'model_params' => ['temperature' => Settings::get('ai.temperature'), 'demo' => Settings::aiDemo()],
                'raw_response' => $out['raw'],
                'parsed' => $parsed,
                'schema_valid' => ! $errors,
                'validation_errors' => $errors ?: null,
                'records_found' => count($parsed['records'] ?? []),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'scan_checksum' => $scan->checksum,
                'processed_at' => now(),
            ]);
            if ($errors && empty($parsed['records'])) {
                throw new AiException('Відповідь не відповідає схемі: '.implode('; ', array_slice($errors, 0, 3)));
            }
            $this->applyRecords($scan, $result, $template, $job);
            $scan->update([
                'recognition_status' => RecognitionStatus::Done, 'recognized_at' => now(), 'needs_recheck' => false,
                'page_label' => $scan->page_label ?: ($parsed['page_label'] ?? null),
            ]);
            $book->refreshCounters();
            Activity::log('recognition.done', $scan, $book->title.' · '.$scan->filename.': записів '.$result->records_found, [], $job?->user);

            return $result;
        });
    }

    /** Повторна обробка не дублює записи й не перезаписує ручних правок без підтвердження (ТЗ 6.3). */
    private function applyRecords(Scan $scan, RecognitionResult $result, RecognitionTemplate $template, ?ProcessingJob $job): void
    {
        $existing = RecordPage::where('scan_id', $scan->id)->where('is_continuation', false)->orderBy('position')->with('record')->get()->values();
        $position = 0;
        foreach ($result->parsed['records'] ?? [] as $row) {
            $fields = $this->mapFields($row, $template);
            if (! empty($row['continuation']) && $prev = $this->previousRecord($scan)) {
                RecordPage::firstOrCreate(['record_id' => $prev->id, 'scan_id' => $scan->id], [
                    'position' => (int) $prev->pages()->max('position') + 1, 'is_continuation' => true, 'region' => $row['row_box'] ?? null,
                ]);

                continue;
            }
            $page = $existing[$position++] ?? null;
            $record = $page?->record;
            if (! $record) {
                $record = Record::create(array_merge($fields['values'], [
                    'book_id' => $scan->book_id,
                    'museum_id' => $scan->book->museum_id,
                    'fund_id' => $scan->book->fund_id,
                    'recognition_result_id' => $result->id,
                    'sequence' => $scan->page_order * 100 + $position,
                    'extra_fields' => $fields['extra'] ?: null,
                    'uncertain_fields' => $fields['uncertain'] ?: null,
                    'field_regions' => $fields['regions'] ?: null,
                    'created_by' => $job?->user_id,
                ]));
                $record->pages()->create(['scan_id' => $scan->id, 'position' => 1, 'region' => $row['row_box'] ?? null]);
                $record->versions()->create(['version' => 1, 'source' => VersionSource::Recognition->value, 'data' => $record->snapshot(), 'user_id' => $job?->user_id]);
                $this->flagDuplicate($record);

                continue;
            }
            $record->forceFill([
                'recognition_result_id' => $result->id,
                'uncertain_fields' => $fields['uncertain'] ?: null,
                'field_regions' => $fields['regions'] ?: $record->field_regions,
            ])->save();
            $data = $fields['values'] + ['extra_fields' => $fields['extra'] ?: null];
            if ($record->has_manual_edits || $record->verification_status === VerificationStatus::Verified) {
                $this->proposeChanges($record, $data, $result);
            } else {
                $record->applyChanges($data, VersionSource::Recognition, $job?->user, comment: 'Повторне розпізнавання, версія результату '.$result->version);
            }
        }
    }

    /** Зміни після повторного розпізнавання для запису з ручними правками — лише як пропозиції. */
    private function proposeChanges(Record $record, array $data, RecognitionResult $result): void
    {
        $diff = [];
        foreach (array_keys(Record::FIELDS) as $f) {
            if (array_key_exists($f, $data) && (string) $record->$f !== (string) $data[$f]) {
                $diff[$f] = $data[$f];
            }
        }
        if (! $diff) {
            return;
        }
        $check = AiCheck::create([
            'record_id' => $record->id, 'status' => AiCheckStatus::Ready, 'record_version' => $record->lock_version,
            'model' => $result->model, 'summary' => 'Повторне розпізнавання сторінки дало інші значення. Ручні правки збережено; оберіть, що прийняти.',
            'finished_at' => now(),
        ]);
        foreach ($diff as $f => $v) {
            $check->proposals()->create(['record_id' => $record->id, 'field' => $f, 'old_value' => $record->$f, 'new_value' => $v, 'reason' => 'Повторне розпізнавання']);
        }
        $record->update(['ai_check_status' => AiCheckStatus::Ready]);
    }

    /** Автоматичне об’єднання за інвентарним номером заборонене; можливі дублікати лише позначаються (ТЗ 7). */
    private function flagDuplicate(Record $record): void
    {
        foreach (['inventory_number', 'account_number'] as $f) {
            if (! $record->$f) {
                continue;
            }
            $dups = Record::where('museum_id', $record->museum_id)->where($f, $record->$f)->whereKeyNot($record->id);
            if ($dups->exists()) {
                $dups->update(['possible_duplicate' => true]);
                $record->update(['possible_duplicate' => true]);
            }
        }
    }

    private function previousRecord(Scan $scan): ?Record
    {
        $prevScan = Scan::where('book_id', $scan->book_id)->where('page_order', '<', $scan->page_order)->orderByDesc('page_order')->first();

        return $prevScan ? Record::whereHas('pages', fn ($q) => $q->where('scan_id', $prevScan->id))->orderByDesc('sequence')->first() : null;
    }

    private function previousPageTail(Scan $scan): ?string
    {
        $r = $this->previousRecord($scan);

        return $r ? mb_substr(collect([$r->record_no, $r->inventory_number, $r->description ?? $r->title])->filter()->implode(' · '), 0, 600) : null;
    }

    private function mapFields(array $row, RecognitionTemplate $template): array
    {
        $values = $extra = [];
        $byKey = collect($template->columns)->keyBy('key');
        foreach ($row['values'] ?? [] as $key => $value) {
            $value = is_scalar($value) ? trim((string) $value) : null;
            if ($value === '' || $value === null) {
                continue;
            }
            $col = $byKey[$key] ?? null;
            $target = $col['maps_to'] ?? null;
            if ($target && array_key_exists($target, Record::FIELDS)) {
                $values[$target] = isset($values[$target]) ? $values[$target].'; '.$value : $value;
            } else {
                $extra[$col['label'] ?? $key] = $value;
            }
        }
        foreach ($row['extra'] ?? [] as $e) {
            if (! empty($e['label']) && isset($e['value']) && $e['value'] !== '') {
                $extra[$e['label']] = $e['value'];
            }
        }
        $toField = fn ($k) => $byKey[$k]['maps_to'] ?? $k;
        $uncertain = collect($row['uncertain'] ?? [])->mapWithKeys(fn ($u) => [$toField($u['field'] ?? '') => $u['reason'] ?? 'Нерозбірливо'])->filter()->all();
        $regions = collect($row['regions'] ?? [])->mapWithKeys(function ($r) use ($toField) {
            $b = $r['box'] ?? null;

            return is_array($b) && count($b) === 4 ? [$toField($r['field']) => ['y' => $b[0] / 1000, 'x' => $b[1] / 1000, 'h' => ($b[2] - $b[0]) / 1000, 'w' => ($b[3] - $b[1]) / 1000]] : [];
        })->all();

        return compact('values', 'extra', 'uncertain', 'regions');
    }

    public function prompt(RecognitionTemplate $t, ?string $prevTail = null): string
    {
        $cols = collect($t->columns)->map(fn ($c, $i) => sprintf('- "%s": графа %s «%s»', $c['key'], $c['column_no'] ?? $i + 1, $c['label']))->implode("\n");

        return trim($t->prompt)."\n\nГрафи книги (ключі для поля values):\n".$cols."\n\n".<<<'TXT'
Правила:
- Один елемент records = один обліковий запис книги (рядок таблиці), а не сторінка.
- Переписуй текст дослівно, мовою оригіналу, зберігаючи скорочення. Нічого не перекладай і не доповнюй.
- Відсутнє значення не заповнюй. Нерозбірливий чи неоднозначний фрагмент залиш як прочитано і додай у uncertain з причиною.
- «— // —», «—"—», «тж» тощо повтори як є; не підставляй значення з рядка вище.
- Якщо перший рядок сторінки продовжує запис з попередньої сторінки, постав continuation=true.
- Текст, що не належить до жодної графи (печатки, підписи, нотатки на полях), поклади в extra з назвою.
- regions: рамки полів у координатах 0–1000 [ymin, xmin, ymax, xmax]; row_box — рамка всього запису.
TXT
            .($prevTail ? "\n\nОстанній запис попередньої сторінки: ".$prevTail : '');
    }

    public function schema(RecognitionTemplate $t): array
    {
        $values = collect($t->columns)->mapWithKeys(fn ($c) => [$c['key'] => ['type' => 'STRING', 'nullable' => true]])->all();
        $box = ['type' => 'ARRAY', 'items' => ['type' => 'NUMBER']];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'page_label' => ['type' => 'STRING', 'nullable' => true],
                'records' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'continuation' => ['type' => 'BOOLEAN'],
                        'values' => ['type' => 'OBJECT', 'properties' => $values],
                        'extra' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['label' => ['type' => 'STRING'], 'value' => ['type' => 'STRING']]]],
                        'uncertain' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['field' => ['type' => 'STRING'], 'reason' => ['type' => 'STRING']]]],
                        'regions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['field' => ['type' => 'STRING'], 'box' => $box]]],
                        'row_box' => $box,
                    ],
                    'required' => ['values'],
                ]],
            ],
            'required' => ['records'],
        ];
    }

    /** Перевірка відповіді за схемою даних (ТЗ 6.2). */
    public function validate(array $json, RecognitionTemplate $t): array
    {
        $errors = [];
        if (! isset($json['records']) || ! is_array($json['records'])) {
            return [['records' => []], ['Немає масиву records']];
        }
        $keys = collect($t->columns)->pluck('key')->all();
        foreach ($json['records'] as $i => $r) {
            if (! is_array($r['values'] ?? null)) {
                $errors[] = 'Запис '.($i + 1).': немає values';

                continue;
            }
            if ($unknown = array_diff(array_keys($r['values']), $keys)) {
                $errors[] = 'Запис '.($i + 1).': невідомі графи '.implode(', ', $unknown);
            }
        }

        return [$json, $errors];
    }

    /** Демо-режим без ключа Gemini: повторює попередній результат сторінки, щоб можна було пройти весь цикл. */
    private function demoResponse(Scan $scan): array
    {
        $prev = $scan->results()->first();
        if (! $prev) {
            throw new AiException('Демо-режим: для цієї сторінки немає збереженого результату. Додайте GEMINI_API_KEY на сервері.');
        }

        return ['json' => $prev->parsed, 'raw' => $prev->raw_response, 'model' => 'demo (без Gemini)'];
    }
}
