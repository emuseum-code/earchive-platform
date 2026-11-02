<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Серверний клієнт Google AI — Gemini AI (ТЗ 6.1). Ключ береться з .env, у браузер не потрапляє.
 * Кожен виклик записується в облік використання та витрат (ТЗ 6.3).
 */
class GeminiClient
{
    /**
     * @param  list<array>  $parts  частини запиту: ['text' => …] або ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64]]
     * @return array{json: array, raw: string, model: string, usage: AiUsage}
     */
    public function generateJson(string $operation, array $parts, array $schema, ?string $model = null, ?int $jobId = null, ?int $userId = null): array
    {
        try {
            return $this->callModel($operation, $parts, $schema, $model, $jobId, $userId);
        } catch (AiException $e) {
            // Google вимикає старі моделі (404 «no longer available»): беремо найновішу Pro зі списку моделей і повторюємо
            if (! str_contains($e->getMessage(), 'помилку 404') || ! ($fresh = $this->replaceRetiredModel($model ?? Settings::get('ai.model')))) {
                throw $e;
            }

            return $this->callModel($operation, $parts, $schema, $fresh, $jobId, $userId);
        }
    }

    /** Список моделей Gemini, що вміють generateContent (без TTS, зображень, ембедингів). */
    public function availableModels(): array
    {
        $resp = Http::timeout(20)->withHeaders(['x-goog-api-key' => (string) Settings::geminiKey()])
            ->get(rtrim(config('services.gemini.endpoint'), '/').'/models', ['pageSize' => 1000]);
        if (! $resp->successful()) {
            return [];
        }

        return collect($resp->json('models', []))
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => \Illuminate\Support\Str::after($m['name'], 'models/'))
            ->filter(fn ($n) => str_starts_with($n, 'gemini-') && ! preg_match('~tts|image|embed|live|audio|computer|robotics|learnlm|nano~', $n))
            ->values()->all();
    }

    /** Найновіша модель тієї ж лінійки (pro / flash): стабільна має перевагу над preview. */
    public function bestModel(string $family = 'pro'): ?string
    {
        return collect($this->availableModels())
            ->filter(fn ($n) => str_contains($n, "-{$family}"))
            ->sortByDesc(fn ($n) => sprintf('%06.2f-%d', (float) (preg_match('~gemini-(\d+(?:\.\d+)?)~', $n, $m) ? $m[1] : 0), preg_match('~preview|exp~', $n) ? 0 : 1))
            ->first();
    }

    private function replaceRetiredModel(string $old): ?string
    {
        $fresh = $this->bestModel(str_contains($old, 'flash') ? 'flash' : 'pro');
        if (! $fresh || $fresh === $old) {
            return null;
        }
        foreach (['ai.model', 'ai.check_model'] as $key) {
            if (Settings::get($key) === $old) {
                Settings::set($key, $fresh);
            }
        }
        \App\Support\Activity::log('ai.model_replaced', null, "Модель {$old} більше не доступна, перейшли на {$fresh}");

        return $fresh;
    }

    private function callModel(string $operation, array $parts, array $schema, ?string $model, ?int $jobId, ?int $userId): array
    {
        $key = Settings::geminiKey();
        if (! $key) {
            throw new AiException('Ключ Gemini AI не налаштовано на сервері (GEMINI_API_KEY).');
        }
        $this->assertBudget();
        $model ??= Settings::get('ai.model');
        $images = count(array_filter($parts, fn ($p) => isset($p['inline_data'])));
        $started = microtime(true);

        try {
            $resp = Http::timeout((int) Settings::get('ai.timeout'))
                ->withHeaders(['x-goog-api-key' => $key])
                ->post(rtrim(config('services.gemini.endpoint'), '/')."/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => [
                        'temperature' => (float) Settings::get('ai.temperature'),
                        'maxOutputTokens' => (int) Settings::get('ai.max_output_tokens'),
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->record($operation, $model, 0, 0, $images, $started, false, $jobId, $userId);
            throw new AiException('Gemini AI не відповідає: '.$e->getMessage(), transient: true);
        }

        $meta = $resp->json('usageMetadata') ?? [];
        $usage = $this->record($operation, $model, (int) ($meta['promptTokenCount'] ?? 0),
            (int) (($meta['candidatesTokenCount'] ?? 0) + ($meta['thoughtsTokenCount'] ?? 0)), $images, $started, $resp->successful(), $jobId, $userId);

        if ($resp->status() === 429 || $resp->serverError()) {
            throw new AiException('Gemini AI тимчасово недоступний або вичерпано ліміт ('.$resp->status().')', transient: true);
        }
        if (! $resp->successful()) {
            throw new AiException('Gemini AI повернув помилку '.$resp->status().': '.mb_substr((string) $resp->json('error.message', $resp->body()), 0, 500));
        }
        $text = collect($resp->json('candidates.0.content.parts', []))->pluck('text')->implode('');
        if ($text === '') {
            throw new AiException('Gemini AI повернув порожню відповідь ('.$resp->json('candidates.0.finishReason', 'невідомо').')', transient: true);
        }
        $json = json_decode($text, true);
        if (! is_array($json)) {
            throw new AiException('Відповідь Gemini AI не є коректним JSON');
        }

        return ['json' => $json, 'raw' => $text, 'model' => $resp->json('modelVersion', $model), 'usage' => $usage];
    }

    public static function imagePart(string $absPath): array
    {
        $data = @file_get_contents($absPath);
        if ($data === false) {
            throw new AiException('Не вдалося прочитати скан: '.basename($absPath));
        }

        return ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($data)]];
    }

    /** Місячний бюджет обробки (ТЗ 6.3): при перевищенні нові запити не надсилаються. */
    public function assertBudget(): void
    {
        $budget = (float) Settings::get('ai.monthly_budget_usd');
        if ($budget > 0 && self::monthSpent() >= $budget) {
            throw new AiException(sprintf('Вичерпано місячний бюджет Gemini AI ($%.2f). Збільште бюджет у налаштуваннях.', $budget));
        }
    }

    public static function monthSpent(): float
    {
        return (float) AiUsage::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    private function record(string $op, string $model, int $in, int $out, int $images, float $started, bool $ok, ?int $jobId, ?int $userId): AiUsage
    {
        $cost = $in / 1e6 * (float) Settings::get('ai.price_input_per_m') + $out / 1e6 * (float) Settings::get('ai.price_output_per_m');

        return AiUsage::create([
            'processing_job_id' => $jobId, 'user_id' => $userId, 'operation' => $op, 'model' => $model,
            'input_tokens' => $in, 'output_tokens' => $out, 'images' => $images, 'cost_usd' => round($cost, 6),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'success' => $ok,
        ]);
    }
}
