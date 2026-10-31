<?php

namespace App\Models;

use App\Enums\RemarkStatus;
use App\Enums\RemarkType;
use App\Models\Concerns\ScopedByBook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Зауваження звірки: поле, область скану, коментар, правильне значення (ТЗ 8.2). */
class ReviewRemark extends Model
{
    use ScopedByBook;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'type' => 'field'];

    protected function casts(): array
    {
        return [
            'type' => RemarkType::class,
            'status' => RemarkStatus::class,
            'region' => 'array',
            'fragment' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Розбиває значення поля на «до / фрагмент / після» для підсвічування в тексті.
     * Спершу за збереженими позиціями, далі пошуком тексту; null — фрагмента в полі вже немає.
     */
    public static function splitFragment(?string $value, ?array $fragment): ?array
    {
        $text = (string) ($fragment['text'] ?? '');
        $value = (string) $value;
        if ($text === '' || $value === '') {
            return null;
        }
        $len = mb_strlen($text);
        $start = $fragment['start'] ?? null;
        if (! is_int($start) || mb_substr($value, $start, $len) !== $text) {
            $start = mb_strpos($value, $text);
        }
        if ($start === false || $start === null) {
            return null;
        }

        return [mb_substr($value, 0, $start), $text, mb_substr($value, $start + $len)];
    }

    public function fieldLabel(): string
    {
        return $this->field ? Record::label($this->field) : $this->type->getLabel();
    }
}
