<?php

namespace App\Console\Commands;

use App\Models\AdaptationRule;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Довідники з тезаурусів РМФУ (вивантаження database/seeders/data/tezaurusy_rmfu_*.csv): класифікації, матеріали,
 * техніки виконання, а також способи надходження. Запускається при кожному деплої; повторний запуск нічого не дублює.
 * Терміни з ID реєстру зіставляються за ID, решта — за назвою. Значення, яких немає в реєстрі (демо-набір),
 * видаляються, а їхні синоніми з книг переносяться на відповідні терміни реєстру.
 */
class ImportThesauri extends Command
{
    protected $signature = 'books:thesauri {file? : CSV-вивантаження тезаурусів РМФУ}';

    protected $description = 'Оновити довідники класифікацій, матеріалів, технік і способів надходження з тезаурусів РМФУ';

    public const COMPOSITION = 'Лише терміни тезаурусів РМФУ: спершу матеріали через кому, після «; » — техніки виконання. '
        .'Обирай найточніший термін, розшифровуй скорочення книги. Напр.: «Папір для друкування; Типографський друк».';

    /** Синоніми з книг для термінів реєстру (довідник => ID або назва терміна => синоніми). */
    private const SYNONYMS = [
        'classification' => [
            10 => ['документи'],
            17 => ['нумізматика'],
            21 => ['знак'],
            22 => ['образотворче мистецтво'],
            63 => ['фото', 'фотографія', 'фотографії'],
        ],
        'material' => [
            329 => ['бумага'],
            416 => ['ткань', 'тканина'],
        ],
        'technique' => [
            190 => ['типограф', 'типограф.', 'типографский', 'друкарський друк'],
        ],
        'acquisition_method' => [
            'Дарування (пожертвування)' => ['дар', 'дарение', 'пожертвование'],
            'Закупівля' => ['куплено', 'закупка', 'покупка'],
            'Заповіт' => ['завещание'],
            'Передача виявлених під час археологічних експедицій' => ['археологическая экспедиция', 'раскопки'],
            'Передача виявлених під час інших експедицій' => ['экспедиция'],
        ],
    ];

    /** Способи надходження (перелік від замовника; у вивантаженні тезаурусів їх немає). */
    private const ACQUISITION_METHODS = [
        'Закупівля', 'Дарування (пожертвування)', 'Заповіт', 'Рішення суду', 'Передача обернених у дохід держави',
        'Передача виявлених під час археологічних експедицій', 'Передача виявлених під час етнографічних експедицій',
        'Передача виявлених під час науково-природничих експедицій', 'Передача виявлених під час інших експедицій',
        'Передача під час будівельних робіт', 'Передача під час ремонтних робіт', 'Передача під час реставраційних робіт',
        'Повернення розшуканих в Україну', 'Передача вилучених на митниці', 'Надходження іншими способами',
    ];

    /** Назва тезауруса у вивантаженні РМФУ => [код довідника, назва довідника]. */
    private const THESAURI = [
        'Класифікації' => ['classification', 'Класифікація'],
        'Матеріали' => ['material', 'Матеріали'],
        'Техніки виконання' => ['technique', 'Техніки виконання'],
    ];

    public function handle(): int
    {
        // найновіше вивантаження тезаурусів з адмінки РМФУ (CSV, «;»), напр. tezaurusy_rmfu_2026-10-30.csv
        $files = glob(database_path('seeders/data/tezaurusy_rmfu_*.csv')) ?: [];
        sort($files);
        $path = $this->argument('file') ?: end($files);
        $terms = $path ? $this->readCsv($path) : [];
        if (! $terms) {
            $this->error('Не знайдено вивантаження тезаурусів РМФУ (database/seeders/data/tezaurusy_rmfu_*.csv)');

            return self::FAILURE;
        }
        $version = preg_match('/(\d{4}-\d{2}-\d{2})/', basename($path), $m) ? $m[1] : date('Y-m-d');
        $terms['acquisition_method'] = array_map(fn ($v) => ['v' => $v], self::ACQUISITION_METHODS);

        DB::transaction(function () use ($terms, $version) {
            foreach ([...array_values(self::THESAURI), ['acquisition_method', 'Спосіб надходження']] as [$code, $name]) {
                if (empty($terms[$code])) {
                    $this->warn("{$name}: у вивантаженні немає термінів, довідник не змінено");

                    continue;
                }
                $this->importDictionary($code, $name, $terms[$code], $version);
            }
            $this->composition();
        });

        return self::SUCCESS;
    }

    /**
     * Колонки: Тезаурус; Рівень; Назва; Назва En; Батьківський термін; Повний шлях; Кінцевий термін; ID у реєстрі; Посилання.
     * Рядки йдуть деревом (термін, потім його підпункти), тож батько — останній термін рівнем вище.
     *
     * @return array<string, list<array{id: int, v: string, en: ?string, p: ?int, g: bool}>>
     */
    private function readCsv(string $path): array
    {
        $fh = @fopen($path, 'r');
        if (! $fh) {
            return [];
        }
        $out = [];
        $last = [];
        fgetcsv($fh, 0, ';'); // заголовок
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 8 || ! isset(self::THESAURI[trim($row[0])])) {
                continue;
            }
            [$code] = self::THESAURI[trim($row[0])];
            $level = (int) $row[1];
            $id = (int) $row[7];
            $en = trim($row[3]);
            $out[$code][] = [
                'id' => $id,
                'v' => trim($row[2]),
                'en' => $en !== '' && $en !== trim($row[2]) ? $en : null,
                'p' => $level > 1 ? ($last[$code][$level - 1] ?? null) : null,
                'g' => mb_strtolower(trim($row[6])) === 'ні',
            ];
            $last[$code][$level] = $id;
        }
        fclose($fh);

        return $out;
    }

    private function importDictionary(string $code, string $name, array $terms, string $version): void
    {
        $d = Dictionary::updateOrCreate(['code' => $code], [
            'name' => $name,
            'version' => $version,
            'description' => 'Тезаурус РМФУ. Код значення — ID терміна в реєстрі (admin.museum.mcsc.gov.ua). Оновлюється при кожному деплої.',
        ]);
        $old = $d->items()->get();
        $byCode = $old->filter(fn ($i) => $i->code !== null && $i->code !== '')->keyBy('code');
        $byValue = $old->groupBy(fn ($i) => Dictionary::normalize($i->value));
        $map = self::SYNONYMS[$code] ?? [];

        $ids = [];
        $kept = [];
        foreach ($terms as $sort => $t) {
            $regId = isset($t['id']) ? (string) $t['id'] : null;
            $norm = Dictionary::normalize($t['v']);
            $item = ($regId !== null ? $byCode->get($regId) : null)
                ?? $byValue->get($norm)?->first(fn ($i) => ! in_array($i->id, $kept, true) && ($regId === null || $i->code === null || $i->code === ''))
                ?? new DictionaryItem(['dictionary_id' => $d->id]);

            // синоніми: ті, що вже були (зокрема додані вручну), синоніми демо-значення з такою самою назвою і з карти вище
            $syn = collect($item->synonyms ?? [])
                ->merge($byValue->get($norm, collect())->flatMap(fn ($i) => $i->synonyms ?? []))
                ->merge($map[$t['id'] ?? $t['v']] ?? []);
            $item->fill([
                'value' => $t['v'],
                'value_en' => $t['en'] ?? null,
                'code' => $regId,
                'parent_id' => isset($t['p']) ? ($ids[$t['p']] ?? null) : null,
                'is_group' => ! empty($t['g']),
                'sort' => $sort,
                'synonyms' => $syn->map(fn ($s) => trim((string) $s))->filter()
                    ->reject(fn ($s) => Dictionary::normalize($s) === $norm)->unique()->values()->all(),
            ])->save();
            $kept[] = $item->id;
            if ($regId !== null) {
                $ids[$t['id']] = $item->id;
            }
        }

        $removed = $d->items()->whereNotIn('id', $kept)->get();
        foreach ($removed as $r) {
            $r->delete();
        }
        $this->info(sprintf('%s: %d значень%s', $name, count($kept), $removed->isNotEmpty() ? ', прибрано не з реєстру: '.$removed->pluck('value')->implode(', ') : ''));
    }

    /** Поле «Матеріал, техніка» заповнюється лише термінами тезаурусів матеріалів і технік. */
    private function composition(): void
    {
        $old = 'Розшифрувати скорочення, перекласти, доповнити техніками з опису: «Папір, Друкарський друк; Рукопис».';
        foreach (AdaptationRule::where('target_field', 'composition')->get() as $rule) {
            $params = $rule->params ?? [];
            if (! isset($params['dictionaries'])) {
                $rule->params = $params + ['dictionaries' => ['material', 'technique']];
            }
            if ($rule->comment === null || $rule->comment === $old) {
                $rule->comment = self::COMPOSITION;
            }
            $rule->save();
        }
    }
}
