<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Персональний акаунт працівника (ТЗ 5): роль і межі доступу архівами та справами. */
class User extends Authenticatable implements FilamentUser
{
    /** Демо-акаунти для кнопок швидкого входу на сторінці логіну. */
    public const DEMO_LOGINS = ['admin@books.test', 'operator@books.test', 'verifier@books.test', 'viewer@books.test'];

    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'can_adapt_export' => 'boolean',
            'can_verify' => 'boolean',
            'can_operate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function museums(): BelongsToMany
    {
        return $this->belongsToMany(Museum::class);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Синхронізація, запуск розпізнавання, черга (оператор і адміністратор). */
    public function canOperate(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Operator], true) || ($this->role === Role::Verifier && $this->can_operate);
    }

    /** Звірка, зауваження, AI-перевірка, підтвердження. Суміщення ролей призначає лише адміністратор. */
    public function canVerify(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Verifier], true) || ($this->role === Role::Operator && $this->can_verify);
    }

    /** Запуск адаптації та експорту: адміністратор, оператор — лише з окремим дозволом (ТЗ 18.1). */
    public function canAdaptExport(): bool
    {
        return $this->isAdmin() || ($this->canOperate() && $this->can_adapt_export);
    }

    public function canEditRecords(): bool
    {
        return $this->canVerify();
    }

    /** Ідентифікатори доступних справ (null — усі). */
    public function bookScope(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return once(fn () => Book::query()
            ->whereIn('museum_id', $this->museums()->pluck('museums.id'))
            ->orWhereIn('id', $this->books()->pluck('books.id'))
            ->pluck('id')->all());
    }

    /** Ідентифікатори архівів, у яких користувач бачить хоча б одну справу (null — усі). */
    public function museumScope(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return once(fn () => $this->museums()->pluck('museums.id')
            ->merge(Book::whereIn('id', $this->books()->pluck('books.id'))->pluck('museum_id'))
            ->unique()->values()->all());
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function verifiedRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Record::class, 'verified_by');
    }

    public function remarks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReviewRemark::class);
    }

    public function aiChecks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiCheck::class);
    }
}
