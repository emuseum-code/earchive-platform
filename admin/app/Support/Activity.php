<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Журнал дій (ТЗ 12): користувачі, синхронізації, AI-запуски, шаблони, адаптації, експорти. */
class Activity
{
    public const ACTIONS = [
        'auth.login' => 'Вхід',
        'user.created' => 'Створено акаунт',
        'user.updated' => 'Змінено акаунт / доступи',
        'settings.updated' => 'Змінено налаштування',
        'sync.started' => 'Запущено синхронізацію',
        'sync.finished' => 'Синхронізацію завершено',
        'folder.resolved' => 'Уточнено папку',
        'scan.reordered' => 'Змінено порядок сторінок',
        'recognition.queued' => 'Запущено розпізнавання',
        'recognition.done' => 'Розпізнано сторінку',
        'recognition.failed' => 'Помилка розпізнавання',
        'queue.paused' => 'Чергу призупинено',
        'queue.resumed' => 'Чергу відновлено',
        'queue.cancelled' => 'Скасовано завдання',
        'queue.retried' => 'Повторено завдання',
        'record.updated' => 'Змінено запис',
        'record.verified' => 'Підтверджено звірку',
        'remark.created' => 'Додано зауваження',
        'remark.resolved' => 'Закрито зауваження',
        'ai_check.started' => 'Запущено «Перевірити AI»',
        'ai_check.finished' => 'AI-перевірку завершено',
        'ai_check.decided' => 'Рішення щодо AI-пропозиції',
        'template.updated' => 'Змінено шаблон розпізнавання',
        'adaptation.created' => 'Адаптовано запис',
        'adaptation.confirmed' => 'Підтверджено адаптацію',
        'rule.updated' => 'Змінено правило адаптації',
        'export.created' => 'Сформовано експорт',
        'export.api' => 'Передано в API реєстру',
    ];

    public static function log(string $action, ?Model $subject = null, ?string $description = null, array $properties = [], ?User $user = null): void
    {
        ActivityLog::create([
            'user_id' => ($user ?? auth()->user())?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
