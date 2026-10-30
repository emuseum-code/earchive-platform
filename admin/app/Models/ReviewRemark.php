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

    public function fieldLabel(): string
    {
        return $this->field ? Record::label($this->field) : $this->type->getLabel();
    }
}
