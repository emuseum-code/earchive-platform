<?php

namespace App\Models;

use App\Enums\FileStatus;
use App\Enums\RecognitionStatus;
use App\Models\Concerns\HasUid;
use App\Models\Concerns\ScopedByBook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** JPG-сторінка справи. Оригінал ніколи не змінюється (ТЗ 4.3). */
class Scan extends Model
{
    use HasUid, ScopedByBook;

    protected $guarded = ['id'];

    protected $attributes = ['file_status' => 'present', 'recognition_status' => 'none', 'needs_recheck' => false, 'page_kind' => 'content'];

    protected function casts(): array
    {
        return [
            'file_status' => FileStatus::class,
            'recognition_status' => RecognitionStatus::class,
            'needs_recheck' => 'boolean',
            'file_mtime' => 'datetime',
            'last_seen_at' => 'datetime',
            'recognized_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(RecognitionResult::class)->orderByDesc('version');
    }

    public function records(): BelongsToMany
    {
        return $this->belongsToMany(Record::class, 'record_pages')->withPivot(['position', 'is_continuation', 'region']);
    }

    public function imageUrl(): string
    {
        return route('scans.image', $this);
    }

    public function thumbUrl(): string
    {
        return route('scans.thumb', $this);
    }

    public function absolutePath(): string
    {
        return rtrim(\App\Support\Settings::get('storage.root'), '/').'/'.$this->path;
    }
}
