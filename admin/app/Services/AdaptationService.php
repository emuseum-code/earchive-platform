<?php

namespace App\Services;

use App\Enums\AdaptationStatus;
use App\Enums\ExportStatus;
use App\Enums\VerificationStatus;
use App\Models\Adaptation;
use App\Models\AdaptationRule;
use App\Models\Dictionary;
use App\Models\ProcessingJob;
use App\Models\Record;
use App\Models\RegistrySchema;
use App\Models\User;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Адаптація перевіреного документа до схеми архівного опису (ТЗ 9): таблиця відповідностей,
 * нормалізація дат, чисел і розмірів, довідники, змістовий розбір Gemini AI, програмна валідація.
 */
class AdaptationService
{
    public function __construct(private GeminiClient $gemini) {}

    public function adapt(Record $record, ?User $user = null, ?ProcessingJob $job = null, ?RegistrySchema $schema = null): Adaptation
    {
        $schema ??= RegistrySchema::active() ?? throw new \RuntimeException('Не налаштовано активну схему реєстру.');
        if ($record->verification_status !== VerificationStatus::Verified) {
            throw new \DomainException('Адаптувати можна лише перевірений документ.');
        }
        $record->update(['adaptation_status' => AdaptationStatus::Processing]);
        $rules = $schema->rules()->where('is_active', true)->get();
        $src = $record->snapshot();
        $values = $sources = $issues = [];

        foreach ($rules->where('use_ai', false) as $rule) {
            [$value, $issue] = $this->apply($rule, $src);
            $values[$rule->target_field] = $value;
            $sources[$rule->target_field] = ['fields' => $rule->source_fields, 'rule' => $rule->transform, 'raw' => $this->raw($rule, $src)];
            if ($issue) {
                $issues[] = ['field' => $rule->target_field, 'level' => 'ambiguity', 'message' => $issue];
            }
        }

        $aiRules = $rules->where('use_ai', true);
        $aiRaw = null;
        if ($aiRules->isNotEmpty()) {
            [$aiValues, $aiRaw, $aiIssues] = $this->aiFields($record, $schema, $aiRules, $src, $job);
            foreach ($aiRules as $rule) {
                $v = $aiValues[$rule->target_field] ?? null;
                if ($v !== null && $rule->transform === 'dictionary') {
                    $code = $rule->params['dictionary'] ?? null;
                    $matched = $code ? Dictionary::match($code, $v) : $v;
                    if ($code && ! $matched) {
                        $issues[] = ['field' => $rule->target_field, 'level' => 'ambiguity', 'message' => '«'.$v.'» немає в довіднику'];
                    }
                    $v = $matched ?? $v;
                }
                if ($v !== null && ! empty($rule->params['dictionaries'])) {
                    $unknown = self::unknownTerms((string) $v, $rule->params['dictionaries']);
                    if ($unknown) {
                        $issues[] = ['field' => $rule->target_field, 'level' => 'ambiguity', 'message' => 'Немає в довідниках: «'.implode('», «', $unknown).'»'];
                    }
                }
                $values[$rule->target_field] = $v;
                $sources[$rule->target_field] = ['fields' => $rule->source_fields, 'rule' => 'ai', 'raw' => $this->raw($rule, $src)];
            }
            $issues = array_merge($issues, $aiIssues);
        }

        return DB::transaction(function () use ($record, $schema, $values, $sources, $issues, $aiRaw, $user) {
            Adaptation::where('record_id', $record->id)->update(['is_current' => false]);
            $a = new Adaptation([
                'record_id' => $record->id,
                'registry_schema_id' => $schema->id,
                'version' => (int) Adaptation::where('record_id', $record->id)->max('version') + 1,
                'source_record_version' => $record->lock_version,
                'rules_version' => $schema->rulesVersion(),
                'is_current' => true,
                'values' => $values,
                'sources' => $sources,
                'ai_raw' => $aiRaw,
                'created_by' => $user?->id,
            ]);
            $this->validate($a, $schema, $issues);
            $a->save();
            $this->syncRecordStatus($record, $a);
            Activity::log('adaptation.created', $record, 'Адаптація v'.$a->version.': '.$a->status->getLabel(), ['adaptation_id' => $a->id], $user);

            return $a;
        });
    }

    /** Ручне виправлення адаптованих значень верифікатором з перевіркою версії. */
    public function updateValues(Adaptation $a, array $values, User $user, int $expectedLock): Adaptation
    {
        return DB::transaction(function () use ($a, $values, $expectedLock) {
            $fresh = Adaptation::whereKey($a->id)->lockForUpdate()->first();
            if ($fresh->lock_version !== $expectedLock) {
                throw new \DomainException('Адаптацію вже змінив інший працівник. Оновіть сторінку.');
            }
            $fresh->values = array_merge($fresh->values, $values);
            $fresh->lock_version++;
            $keep = collect($fresh->issues ?? [])->where('level', 'ambiguity')->reject(fn ($i) => array_key_exists($i['field'], $values))->values()->all();
            $this->validate($fresh, $fresh->schema, $keep);
            $fresh->save();
            $this->syncRecordStatus($fresh->record, $fresh);

            return $fresh;
        });
    }

    /** Підтвердження адаптації верифікатором: лише без помилок і для актуальної версії документа. */
    public function confirm(Adaptation $a, User $user): void
    {
        if ($a->isStale()) {
            throw new \DomainException('Адаптація неактуальна: джерельний документ змінився. Запустіть адаптацію повторно.');
        }
        if ($a->errors_count > 0) {
            throw new \DomainException('Виправте обов’язкові поля та помилки формату ('.$a->errors_count.').');
        }
        $a->update(['status' => AdaptationStatus::Adapted, 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'warnings_count' => 0,
            'issues' => collect($a->issues)->reject(fn ($i) => $i['level'] === 'ambiguity')->values()->all()]);
        $this->syncRecordStatus($a->record, $a);
        Activity::log('adaptation.confirmed', $a->record, 'Підтверджено адаптацію v'.$a->version, [], $user);
    }

    private function syncRecordStatus(Record $record, Adaptation $a): void
    {
        $ready = $a->status === AdaptationStatus::Adapted && $a->errors_count === 0;
        $record->update([
            'adaptation_status' => $a->status,
            'export_status' => $record->export_status === ExportStatus::Exported && $ready ? ExportStatus::Exported : ($ready ? ExportStatus::Ready : ExportStatus::NotReady),
        ]);
        $record->book->refreshCounters();
    }

    /** Програмна перевірка типів, обов’язкових полів і допустимих значень. */
    public function validate(Adaptation $a, RegistrySchema $schema, array $issues = []): void
    {
        $values = $a->values;
        foreach ($schema->fields as $f) {
            $v = $values[$f->key] ?? null;
            if ($f->required && ($v === null || $v === '')) {
                $issues[] = ['field' => $f->key, 'level' => 'error', 'message' => 'Обов’язкове поле не заповнено'];

                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            $bad = match ($f->type) {
                'date' => ! preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', (string) $v),
                'year' => ! preg_match('/^-?\d{1,4}$/', (string) $v),
                'number', 'money' => ! is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)),
                'bool' => ! in_array((string) $v, ['0', '1', 'так', 'ні'], true),
                'dictionary' => $f->dictionary_id && ! collect(explode(';', (string) $v))->every(fn ($p) => $f->dictionary->items->contains(fn ($i) => Dictionary::normalize($i->value) === Dictionary::normalize($p))),
                default => false,
            };
            if ($bad) {
                $issues[] = ['field' => $f->key, 'level' => $f->type === 'dictionary' ? 'ambiguity' : 'error',
                    'message' => $f->type === 'dictionary' ? 'Значення немає в довіднику «'.$f->dictionary?->name.'»' : 'Неправильний формат ('.$f->type.')'];
            }
        }
        foreach ([['year_from', 'year_to']] as [$from, $to]) {
            if (is_numeric($values[$from] ?? null) && is_numeric($values[$to] ?? null) && $values[$from] > $values[$to]) {
                $issues[] = ['field' => $to, 'level' => 'error', 'message' => 'Рік «До» менший за рік «З»'];
            }
        }
        $issues = collect($issues)->unique(fn ($i) => $i['field'].$i['message'])->values()->all();
        $a->issues = $issues;
        $a->errors_count = collect($issues)->where('level', 'error')->count();
        $a->warnings_count = collect($issues)->where('level', '!=', 'error')->count();
        $a->status = $a->errors_count || $a->warnings_count ? AdaptationStatus::NeedsClarification : AdaptationStatus::Adapted;
    }

    /** Частини складеного значення («Папір, Картон; Друк»), яких немає в жодному з довідників. */
    public static function unknownTerms(string $value, array $dictionaries): array
    {
        return collect(preg_split('/[;,]/u', $value))->map(fn ($p) => trim($p))->filter()
            ->reject(fn ($p) => collect($dictionaries)->contains(fn ($code) => Dictionary::match($code, $p) !== null))
            ->values()->all();
    }

    private function raw(AdaptationRule $rule, array $src): ?string
    {
        return collect($rule->source_fields)->map(fn ($f) => $src[$f] ?? null)->filter()->implode(' | ') ?: null;
    }

    /** @return array{0: mixed, 1: ?string} значення і неоднозначність */
    public function apply(AdaptationRule $rule, array $src): array
    {
        $raw = $this->raw($rule, $src);
        $p = $rule->params ?? [];
        if ($rule->transform === 'constant') {
            return [$p['value'] ?? null, null];
        }
        if ($raw === null) {
            return [null, null];
        }

        return match ($rule->transform) {
            'date' => self::normalizeDate($raw),
            'year_from', 'year_to' => [self::years($raw)[$rule->transform === 'year_from' ? 0 : 1], null],
            'sizes' => self::sizes($raw),
            'number' => [is_numeric($n = str_replace([' ', ','], ['', '.'], preg_replace('/[^\d,.\s]/u', '', $raw))) ? $n + 0 : null, null],
            'money' => [is_numeric($n = str_replace([' ', ','], ['', '.'], preg_replace('/[^\d,.\s]/u', '', $raw))) ? round($n, 2) : null, null],
            'regex' => [preg_match('~'.($p['pattern'] ?? '(.*)').'~u', $raw, $m) ? trim($m[1] ?? $m[0]) : null, null],
            'dictionary' => ($d = Dictionary::match($p['dictionary'] ?? '', $raw)) ? [$d, null] : [$raw, '«'.$raw.'» не зіставлено з довідником'],
            default => [$raw, null],
        };
    }

    private const MONTHS = [
        'січ' => 1, 'лют' => 2, 'берез' => 3, 'квіт' => 4, 'трав' => 5, 'черв' => 6, 'лип' => 7, 'серп' => 8, 'верес' => 9, 'жовт' => 10, 'листоп' => 11, 'груд' => 12,
        'янв' => 1, 'февр' => 2, 'март' => 3, 'апрел' => 4, 'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
    ];

    /** «28.08.1996 г.», «12 березня 1927 р.», «02.1993», «1975 р.» → 1996-08-28 / 1927-03-12 / 1993-02 / 1975. */
    public static function normalizeDate(string $raw): array
    {
        if (preg_match('/\b(\d{1,2})\s+([[:alpha:]]+)\.?\s+(\d{4})/u', $raw, $m)) {
            $word = mb_strtolower($m[2]);
            foreach (self::MONTHS as $stem => $n) {
                if (str_starts_with($word, $stem) && ($stem !== 'ма' || in_array($word, ['мая', 'май'], true))) {
                    return [sprintf('%04d-%02d-%02d', $m[3], $n, $m[1]), null];
                }
            }
        }
        if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\b/u', $raw, $m)) {
            return [sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), null];
        }
        if (preg_match('/\b(\d{1,2})[.\/](\d{4})\b/u', $raw, $m)) {
            return [sprintf('%04d-%02d', $m[2], $m[1]), null];
        }
        if (preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/u', $raw, $m)) {
            return [$m[1], null];
        }

        return [null, 'Дату «'.$raw.'» не розпізнано'];
    }

    /** @return array{0: ?int, 1: ?int} */
    public static function years(string $raw): array
    {
        preg_match_all('/\b(1[0-9]\d{2}|20\d{2})\b/u', $raw, $m);
        $ys = array_map('intval', $m[1]);

        return $ys ? [min($ys), max($ys)] : [null, null];
    }

    /** «10,5 х 7,5 см» → «H 10,5 см; W 7,5 см». */
    public static function sizes(string $raw): array
    {
        $unit = preg_match('/\b(мм|см|м|mm|cm)\b/u', $raw, $u) ? $u[1] : 'см';
        $nums = preg_split('/\s*[хx×*]\s*/u', trim(preg_replace('/(мм|см|м|mm|cm)\.?/u', '', $raw)));
        $nums = array_values(array_filter(array_map(fn ($n) => trim($n, ' .;'), $nums), fn ($n) => preg_match('/^\d+([.,]\d+)?$/', $n)));
        if (! $nums || count($nums) > 3) {
            return [$raw, 'Розміри «'.$raw.'» не розібрано'];
        }
        $codes = ['H', 'W', 'D'];

        return [collect($nums)->map(fn ($n, $i) => $codes[$i].' '.$n.' '.$unit)->implode('; '), null];
    }

    /** Один запит Gemini AI на всі змістові поля документа. */
    private function aiFields(Record $record, RegistrySchema $schema, $rules, array $src, ?ProcessingJob $job): array
    {
        $fields = $schema->fields->keyBy('key');
        if (! Settings::geminiKey()) {
            // без ключа — переносимо джерельний текст, а неоднозначність лишаємо верифікатору
            $values = $issues = [];
            foreach ($rules as $r) {
                $values[$r->target_field] = in_array($r->transform, ['translate', 'title', 'copy'], true) ? $this->raw($r, $src) : null;
                $issues[] = ['field' => $r->target_field, 'level' => 'ambiguity', 'message' => 'Потребує змістового розбору Gemini AI (ключ не налаштовано)'];
            }

            return [$values, null, $issues];
        }
        $spec = $rules->map(function ($r) use ($fields) {
            $f = $fields[$r->target_field] ?? null;
            $dict = $f?->dictionary ? ' Допустимі значення: '.$f->dictionary->items->pluck('value')->unique()->implode('; ').'.' : '';
            foreach (Dictionary::whereIn('code', $r->params['dictionaries'] ?? [])->with('items')->get() as $d) {
                $dict .= ' '.$d->name.': '.$d->items->pluck('value')->unique()->implode('; ').'.';
            }

            return sprintf('- %s: %s. %s%s', $r->target_field, $f?->label ?? $r->target_field, $r->comment ?? AdaptationRule::TRANSFORMS[$r->transform] ?? '', $dict);
        })->implode("\n");
        $prompt = "Склади описову статтю архівного документа за полями схеми архівного опису (ISAD(G), рівень «документ»).\n"
            .'Мова опису — українська; поля *_en — англійською. Не вигадуй даних, яких немає в документі; '
            ."якщо значення не можна визначити — поверни null і поясни в issues.\n\nПоля:\n".$spec
            ."\n\nДокумент справи:\n".json_encode($src, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $schemaJson = [
            'type' => 'OBJECT',
            'properties' => [
                'values' => ['type' => 'OBJECT', 'properties' => $rules->mapWithKeys(fn ($r) => [$r->target_field => ['type' => 'STRING', 'nullable' => true]])->all()],
                'issues' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['field' => ['type' => 'STRING'], 'message' => ['type' => 'STRING']]]],
            ],
            'required' => ['values'],
        ];
        $out = $this->gemini->generateJson('adapt', [['text' => $prompt]], $schemaJson, Settings::get('ai.model'), $job?->id, $job?->user_id);
        $issues = collect($out['json']['issues'] ?? [])->map(fn ($i) => ['field' => $i['field'] ?? '', 'level' => 'ambiguity', 'message' => $i['message'] ?? ''])->all();

        return [$out['json']['values'] ?? [], $out['raw'], $issues];
    }
}
