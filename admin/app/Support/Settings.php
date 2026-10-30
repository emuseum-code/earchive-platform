<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\SettingVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Налаштування системи з історією версій. Ключі API тут не зберігаються:
 * GEMINI_API_KEY лежить лише в .env на сервері (ТЗ 12).
 */
class Settings
{
    public const DEFAULTS = [
        'storage.root' => null,
        'storage.allow_create_folders' => false,
        'storage.checksum' => false,
        'storage.schedule' => 'off', // off | hourly | daily | weekly
        'storage.extensions' => ['jpg', 'jpeg'],
        'ai.model' => 'gemini-2.5-pro',
        'ai.check_model' => 'gemini-2.5-pro',
        'ai.temperature' => 0.1,
        'ai.max_output_tokens' => 32768,
        'ai.timeout' => 180,
        'ai.requests_per_minute' => 30,
        'ai.concurrency' => 4,
        'ai.max_attempts' => 3,
        'ai.monthly_budget_usd' => 500,
        'ai.price_input_per_m' => 1.25,
        'ai.price_output_per_m' => 10.0,
        'ai.demo_mode' => false,
        'ai.mass_processing_allowed' => false,
        'queue.paused' => false,
        'registry.api_url' => null,
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings', fn () => Setting::pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        $setting = Setting::firstOrNew(['key' => $key]);
        if ($setting->exists && $setting->value === $value) {
            return;
        }
        $setting->version = $setting->exists ? $setting->version + 1 : 1;
        $setting->value = $value;
        $setting->updated_by = auth()->id();
        $setting->save();
        SettingVersion::create(['key' => $key, 'version' => $setting->version, 'value' => $value, 'user_id' => auth()->id()]);
        Cache::forget('settings');
    }

    public static function all(): array
    {
        return array_merge(self::DEFAULTS, Setting::pluck('value', 'key')->all());
    }

    public static function setMany(array $values): void
    {
        foreach (Arr::dot($values) as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS) || str_starts_with($k, 'storage.extensions')) {
                self::set($k, $v);
            }
        }
    }

    public static function geminiKey(): ?string
    {
        return config('services.gemini.key') ?: null;
    }

    public static function aiDemo(): bool
    {
        return ! self::geminiKey() && (bool) self::get('ai.demo_mode');
    }
}
