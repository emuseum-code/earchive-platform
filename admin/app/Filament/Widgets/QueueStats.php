<?php

namespace App\Filament\Widgets;

use App\Enums\JobStatus;
use App\Models\AiUsage;
use App\Models\ProcessingJob;
use App\Services\GeminiClient;
use App\Support\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Стан черги та використання Gemini AI (ТЗ 6.3, 10). */
class QueueStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $count = fn (JobStatus $s) => ProcessingJob::where('status', $s->value)->count();
        $spent = GeminiClient::monthSpent();
        $budget = (float) Settings::get('ai.monthly_budget_usd');
        $month = AiUsage::where('created_at', '>=', now()->startOfMonth());

        return [
            Stat::make('У черзі', $count(JobStatus::Queued))->description(Settings::get('queue.paused') ? 'Обробку призупинено' : 'Обробка активна')
                ->color(Settings::get('queue.paused') ? 'warning' : 'success')->icon('heroicon-o-queue-list'),
            Stat::make('Виконується', $count(JobStatus::Running))->icon('heroicon-o-cog-6-tooth'),
            Stat::make('Помилки', $count(JobStatus::Failed))->color($count(JobStatus::Failed) ? 'danger' : null)->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Gemini AI за місяць', '$'.number_format($spent, 2))
                ->description(($budget > 0 ? 'бюджет $'.number_format($budget, 0).' · ' : '').number_format((clone $month)->count()).' запитів · '
                    .number_format(((clone $month)->sum('input_tokens') + (clone $month)->sum('output_tokens')) / 1000, 0).' тис. токенів')
                ->color($budget > 0 && $spent > $budget * 0.8 ? 'danger' : null)->icon('heroicon-o-sparkles'),
        ];
    }
}
