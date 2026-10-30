<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\RecognitionStatus;
use App\Jobs\RunProcessingJob;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Черга обробки поверх таблиці processing_jobs (ТЗ 6.3): пауза, скасування невиконаних, ручний повтор,
 * обмежені автоматичні повтори й відновлення після перезапуску. Стан черги живе в базі, тож закриття
 * браузера чи перезапуск воркера нічого не губить.
 */
class QueueManager
{
    public function enqueue(JobType $type, Model $target, ?User $user, ?string $batch = null, array $payload = [], int $priority = 100): ProcessingJob
    {
        // не ставимо в чергу те саме завдання двічі
        $existing = ProcessingJob::where('type', $type->value)->where('target_type', $target->getMorphClass())->where('target_id', $target->getKey())
            ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value, JobStatus::Paused->value])->first();
        if ($existing) {
            return $existing;
        }
        $job = ProcessingJob::create([
            'type' => $type, 'status' => JobStatus::Queued, 'target_type' => $target->getMorphClass(), 'target_id' => $target->getKey(),
            'user_id' => $user?->id, 'batch_key' => $batch, 'payload' => $payload ?: null, 'priority' => $priority,
            'max_attempts' => (int) Settings::get('ai.max_attempts'),
        ]);
        RunProcessingJob::dispatch($job->id)->onQueue($type === JobType::AiCheck ? 'interactive' : 'default');

        return $job;
    }

    /** @param iterable<\App\Models\Scan> $scans */
    public function enqueueRecognition(iterable $scans, ?User $user): array
    {
        $batch = (string) Str::uuid();
        $n = 0;
        foreach ($scans as $scan) {
            if ($scan->file_status->value !== 'present') {
                continue;
            }
            $this->enqueue(JobType::Recognize, $scan, $user, $batch);
            $scan->update(['recognition_status' => RecognitionStatus::Queued]);
            $n++;
        }
        Activity::log('recognition.queued', null, 'Сторінок у черзі: '.$n, ['batch' => $batch], $user);

        return [$batch, $n];
    }

    public function pauseAll(): void
    {
        Settings::set('queue.paused', true);
        Activity::log('queue.paused');
    }

    public function resumeAll(): void
    {
        Settings::set('queue.paused', false);
        Activity::log('queue.resumed');
        $this->recover();
    }

    public function cancel(ProcessingJob $job): bool
    {
        $ok = ProcessingJob::whereKey($job->id)->whereIn('status', [JobStatus::Queued->value, JobStatus::Paused->value])
            ->update(['status' => JobStatus::Cancelled->value, 'finished_at' => now()]) > 0;
        if ($ok) {
            $this->resetTarget($job->refresh());
            Activity::log('queue.cancelled', $job);
        }

        return $ok;
    }

    public function retry(ProcessingJob $job): void
    {
        if (! in_array($job->status, [JobStatus::Failed, JobStatus::Cancelled], true)) {
            return;
        }
        $job->update(['status' => JobStatus::Queued, 'attempts' => 0, 'error' => null, 'available_at' => null, 'started_at' => null, 'finished_at' => null]);
        if ($job->type === JobType::Recognize) {
            $job->target?->update(['recognition_status' => RecognitionStatus::Queued]);
        }
        RunProcessingJob::dispatch($job->id)->onQueue($job->type === JobType::AiCheck ? 'interactive' : 'default');
        Activity::log('queue.retried', $job);
    }

    /**
     * Відновлення після збою: завислі «виконується» повертаються в чергу, усі невиконані
     * перевідправляються воркеру. Дублювання немає: воркер атомарно бере лише завдання в стані «у черзі».
     */
    public function recover(int $staleMinutes = 15): int
    {
        ProcessingJob::where('status', JobStatus::Running->value)->where('started_at', '<', now()->subMinutes($staleMinutes))
            ->update(['status' => JobStatus::Queued->value]);
        $n = 0;
        ProcessingJob::where('status', JobStatus::Queued->value)->select(['id', 'type'])->chunkById(1000, function ($jobs) use (&$n) {
            foreach ($jobs as $j) {
                RunProcessingJob::dispatch($j->id)->onQueue($j->type === JobType::AiCheck ? 'interactive' : 'default');
                $n++;
            }
        });

        return $n;
    }

    private function resetTarget(ProcessingJob $job): void
    {
        if ($job->type === JobType::Recognize && $job->target && $job->target->recognition_status === RecognitionStatus::Queued) {
            $job->target->update(['recognition_status' => $job->target->recognized_at ? RecognitionStatus::Done : RecognitionStatus::None]);
        }
    }
}
