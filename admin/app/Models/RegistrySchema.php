<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Цільова схема архівного опису (шаблон міграції даних РМФУ). */
class RegistrySchema extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(RegistryField::class)->orderBy('sort');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AdaptationRule::class)->orderBy('sort');
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->latest('id')->first();
    }

    public function rulesVersion(): int
    {
        return (int) $this->rules()->sum('version');
    }
}
