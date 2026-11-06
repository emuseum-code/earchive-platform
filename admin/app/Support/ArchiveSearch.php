<?php

namespace App\Support;

use App\Models\Museum;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Пошук по слову в усьому архіві: повний текст, заголовок, зміст, особи, місця, установи, шифри.
 * Кілька слів — документ має містити кожне (у будь-якому з полів). Регістр не важливий (Postgres — ilike).
 */
class ArchiveSearch
{
    /** Поля пошуку з підписами для «де знайдено» */
    public const FIELDS = [
        'full_text' => 'Повний текст', 'title' => 'Заголовок', 'description' => 'Зміст', 'persons' => 'Особи',
        'places' => 'Географічні назви', 'organizations' => 'Установи', 'author' => 'Автор', 'addressee' => 'Адресат',
        'notes' => 'Примітки', 'doc_index' => 'Індекс', 'archive_code' => 'Шифр',
    ];

    /** @return list<string> слова запиту (лапки — точна фраза) */
    public static function terms(string $q): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/u', trim($q), $m, PREG_SET_ORDER);

        return array_values(array_filter(array_map(fn ($x) => trim($x[1] !== '' ? $x[1] : $x[2], " \t,.;:«»"), $m), fn ($t) => mb_strlen($t) >= 2));
    }

    public static function query(string $q, ?User $user = null, ?int $museumId = null): Builder
    {
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = Record::query()->with(['book.fund', 'museum']);
        if ($user) {
            $query->whereIn('museum_id', Museum::visibleTo($user)->select('id'));
        }
        foreach (self::terms($q) as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function (Builder $w) use ($like, $op, $term) {
                foreach (array_keys(self::FIELDS) as $f) {
                    $w->orWhere($f, $op, $like);
                }
                // SQLite не знижує кирилицю: пробуємо ще з великої літери й усе великими
                if ($op === 'like') {
                    foreach ([mb_convert_case($term, MB_CASE_TITLE), mb_strtoupper($term), mb_strtolower($term)] as $v) {
                        $w->orWhere('full_text', 'like', '%'.addcslashes($v, '%_\\').'%')->orWhere('title', 'like', '%'.addcslashes($v, '%_\\').'%');
                    }
                }
            });
        }

        return $query->when($museumId, fn (Builder $query) => $query->where('museum_id', $museumId))
            ->orderBy('museum_id')->orderBy('book_id')->orderBy('sequence');
    }

    /**
     * Уривки з підсвіткою: до $limit місць збігу з кожного поля, з контекстом.
     *
     * @return Collection<int, array{field: string, html: string}>
     */
    public static function snippets(Record $record, string $q, int $limit = 3, int $around = 90): Collection
    {
        $terms = self::terms($q);
        $out = collect();
        foreach (self::FIELDS as $field => $label) {
            $text = (string) $record->$field;
            if ($text === '') {
                continue;
            }
            $hits = 0;
            $lastEnd = -1;
            foreach ($terms as $term) {
                $offset = 0;
                while ($hits < $limit && ($pos = mb_stripos($text, $term, $offset)) !== false) {
                    $offset = $pos + mb_strlen($term);
                    if ($pos < $lastEnd) {
                        continue;
                    }
                    $start = max(0, $pos - $around);
                    $end = min(mb_strlen($text), $pos + mb_strlen($term) + $around);
                    $lastEnd = $end;
                    $piece = mb_substr($text, $start, $end - $start);
                    $out->push(['field' => $label, 'html' => ($start > 0 ? '…' : '').self::highlight($piece, $terms).($end < mb_strlen($text) ? '…' : '')]);
                    $hits++;
                }
            }
        }

        return $out;
    }

    public static function highlight(string $text, array $terms): string
    {
        $html = e(preg_replace('/\s+/u', ' ', $text));
        foreach ($terms as $t) {
            $html = preg_replace('/('.preg_quote(e($t), '/').')/iu', '<mark>$1</mark>', $html);
        }

        return $html;
    }
}
