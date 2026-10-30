<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Вихідна відповідь Gemini AI для сторінки та її структурований розбір (ТЗ 6.2). */
class RecognitionResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parsed' => 'array',
            'model_params' => 'array',
            'validation_errors' => 'array',
            'schema_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecognitionTemplate::class, 'recognition_template_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
