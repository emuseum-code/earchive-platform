<?php

namespace App\Jobs;

use App\Enums\AdaptationStatus;
use App\Enums\AiCheckStatus;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\RecognitionStatus;
use App\Models\ProcessingJob;
use App\Services\AdaptationService;
use App\Services\AiCheckService;
use App\Services\AiException;
use App\Services\ExportService;
use App\Services\RecognitionService;
use App\Services\StorageSync;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\RateLimiter;

/** Виконавець одного завдання з processing_jobs. */
class RunProcessingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public int $jobId) {}

    public function handle(): void
    {
        $job = ProcessingJob::find($this->jobId);
        if (! $job || $job->status !== JobStatus::Queued) {
            return;
        }
        // Пауза черги: інтерактивна «Перевірити AI» не блокується масовою паузою
        if (Settings::get('queue.paused') && $job->type !== JobType::AiCheck) {
            $this->release(60);

            return;
        }
        if ($job->available_at && $job->available_at->isFuture()) {
            $this->release(max(5, now()->diffInSeconds($job->available_at)));

            return;
        }
        $usesAi = in_array($job->type, [JobType::Recognize, JobType::AiCheck, JobType::Adapt], true);
        if ($usesAi && ! RateLimiter::attempt('gemini', (int) Settings::get('ai.requests_per_minute'), fn () => true, 60)) {
            $this->release(10);

            return;
        }
        // атомарне захоплення: два воркери не виконають одне завдання
        if (! ProcessingJob::whereKey($job->id)->where('status', JobStatus::Queued->value)
            ->update(['status' => JobStatus::Running->value, 'started_at' => now(), 'attempts' => $job->attempts + 1])) {
            return;
        }
        $job->refresh();

        try {
            $target = $job->target;
            match ($job->type) {
                JobType::Recognize => app(RecognitionService::class)->recognize($target, $job),
                JobType::AiCheck => app(AiCheckService::class)->run($target, $job),
                JobType::Adapt => app(AdaptationService::class)->adapt($target, $job->user, $job),
                JobType::Export => app(ExportService::class)->build($target),
                JobType::Sync => app(StorageSync::class)->run($target),
            };
            $job->update(['status' => JobStatus::Done, 'finished_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $transient = $e instanceof AiException && $e->transient;
            if ($transient && $job->attempts < $job->max_attempts) {
                // обмежені автоматичні повтори з наростаючою паузою
                $delay = 30 * 2 ** ($job->attempts - 1);
                $job->update(['status' => JobStatus::Queued, 'error' => $e->getMessage(), 'available_at' => now()->addSeconds($delay)]);
                self::dispatch($job->id)->onQueue($this->queue)->delay($delay);

                return;
            }
            $job->update(['status' => JobStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->markTargetFailed($job, $e->getMessage());
            if (! $e instanceof AiException && ! $e instanceof \DomainException) {
                report($e);
            }
        }
    }

    private function markTargetFailed(ProcessingJob $job, string $error): void
    {
        $t = $job->target;
        match ($job->type) {
            JobType::Recognize => $t?->update(['recognition_status' => RecognitionStatus::Failed, 'error' => $error]),
            JobType::AiCheck => tap($t, fn ($c) => $c?->update(['status' => AiCheckStatus::Failed, 'error' => $error, 'finished_at' => now()]))
                ?->record?->update(['ai_check_status' => AiCheckStatus::Failed]),
            JobType::Adapt => $t?->update(['adaptation_status' => AdaptationStatus::Failed]),
            default => null,
        };
        if ($job->type === JobType::Recognize) {
            Activity::log('recognition.failed', $t, $error, [], $job->user);
            $t?->book?->refreshCounters();
        }
    }
}
