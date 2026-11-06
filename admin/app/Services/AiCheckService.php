<?php

namespace App\Services;

use App\Enums\AiCheckStatus;
use App\Enums\ProposalDecision;
use App\Enums\RemarkStatus;
use App\Enums\VersionSource;
use App\Models\AiCheck;
use App\Models\AiCheckProposal;
use App\Models\ProcessingJob;
use App\Models\Record;
use App\Models\User;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Кнопка «Перевірити AI» (ТЗ 8.3–8.4): надсилає скани, попередній результат, зауваження, області
 * й запропоновані виправлення. Результат — окрема версія з пропозиціями; AI нічого не підтверджує сам.
 */
class AiCheckService
{
    public function __construct(private GeminiClient $gemini) {}

    public function run(AiCheck $check, ?ProcessingJob $job = null): AiCheck
    {
        $record = $check->record()->with(['scans', 'openRemarks'])->first();
        $remarks = $record->openRemarks;
        $check->update(['status' => AiCheckStatus::Running]);
        $record->update(['ai_check_status' => AiCheckStatus::Running]);

        $request = [
            'record' => $record->snapshot(),
            'uncertain' => $record->uncertain_fields,
            'remarks' => $remarks->map(fn ($r) => [
                'type' => $r->type->value, 'field' => $r->field, 'comment' => $r->comment,
                'current_value' => $r->current_value, 'suggested_value' => $r->suggested_value,
                'page' => $r->scan?->filename, 'region' => $r->region,
                'mismatched_text' => $r->fragment['text'] ?? null,
            ])->values()->all(),
            'pages' => $record->scans->pluck('filename')->all(),
        ];
        $check->update(['request' => $request, 'whole_record' => $remarks->isEmpty(), 'remark_ids' => $remarks->pluck('id')->all(), 'scan_ids' => $record->scans->pluck('id')->all()]);

        if (Settings::aiDemo()) {
            $out = $this->demo($record, $remarks);
        } else {
            $parts = [['text' => $this->prompt($request)]];
            foreach ($record->scans as $scan) {
                $parts[] = ['text' => 'Сторінка: '.$scan->filename.($scan->pivot->is_continuation ? ' (продовження документа)' : '')];
                $parts[] = GeminiClient::imagePart($scan->absolutePath());
            }
            $out = $this->gemini->generateJson('ai_check', $parts, $this->schema(), Settings::get('ai.check_model'), $job?->id, $check->user_id);
        }

        DB::transaction(function () use ($check, $record, $out) {
            $proposals = 0;
            foreach ($out['json']['proposals'] ?? [] as $p) {
                $field = $p['field'] ?? null;
                if (! array_key_exists($field, Record::FIELDS)) {
                    continue;
                }
                $new = isset($p['new_value']) && trim((string) $p['new_value']) !== '' ? trim((string) $p['new_value']) : null;
                if ((string) $record->$field === (string) $new) {
                    continue;
                }
                $check->proposals()->create([
                    'record_id' => $record->id, 'field' => $field, 'old_value' => $record->$field, 'new_value' => $new,
                    'reason' => $p['reason'] ?? null, 'confidence' => isset($p['confidence']) ? min(1, max(0, (float) $p['confidence'])) : null,
                ]);
                $proposals++;
            }
            $check->update([
                'status' => AiCheckStatus::Ready, 'raw_response' => $out['raw'], 'model' => $out['model'],
                'summary' => ($out['json']['summary'] ?? null) ?: ($proposals ? null : 'Розбіжностей з оригіналом не знайдено.'),
                'finished_at' => now(),
            ]);
            $record->update(['ai_check_status' => AiCheckStatus::Ready]);
            Activity::log('ai_check.finished', $record, 'Пропозицій: '.$proposals, ['ai_check_id' => $check->id], $check->user);
        });

        return $check->refresh();
    }

    /** Рішення працівника щодо пропозиції: прийняти, відхилити або виправити вручну (ТЗ 8.4). */
    public function decide(AiCheckProposal $p, ProposalDecision $decision, User $user, ?string $value = null, ?int $expectedLock = null): void
    {
        DB::transaction(function () use ($p, $decision, $user, $value, $expectedLock) {
            $record = $p->record;
            if (in_array($decision, [ProposalDecision::Accepted, ProposalDecision::Edited], true)) {
                $final = $decision === ProposalDecision::Edited ? $value : $p->new_value;
                $record->applyChanges([$p->field => $final], VersionSource::AiAccepted, $user, $expectedLock,
                    'AI-пропозицію '.($decision === ProposalDecision::Edited ? 'виправлено вручну' : 'прийнято').': '.Record::label($p->field), $p->ai_check_id);
                // прийнята правка закриває відкриті зауваження до цього поля
                $record->remarks()->where('field', $p->field)->where('status', RemarkStatus::Open->value)
                    ->update(['status' => RemarkStatus::Resolved->value, 'resolved_by' => $user->id, 'resolved_at' => now()]);
                $p->final_value = $final;
            }
            $p->decision = $decision;
            $p->decided_by = $user->id;
            $p->decided_at = now();
            $p->save();
            Activity::log('ai_check.decided', $record, Record::label($p->field).': '.$decision->getLabel(), ['proposal_id' => $p->id], $user);
        });
    }

    private function prompt(array $request): string
    {
        $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<TXT
Ти перевіряєш розпізнаний документ архівної справи за оригінальними сканами.
Нижче — поточні значення полів, нерозбірливі місця та зауваження працівника.
Якщо зауважень немає — звір увесь документ з оригіналом. Якщо зауваження є — насамперед перевір саме їх. mismatched_text — фрагмент поля, який працівник позначив як такий,
що не збігається зі сканом; region — область на сторінці в частках ширини й висоти (x, y, w, h від лівого верхнього кута).
Документ може продовжуватися на кількох сторінках; враховуй усі надані сторінки.
Пропонуй зміну лише там, де значення на скані відрізняється від поточного. Пиши дослівно мовою оригіналу,
нічого не вигадуй і не доповнюй. Якщо значення не читається, не пропонуй його, а поясни в summary.
У proposals.field використовуй ключі з record. Коротко поясни причину кожної зміни (reason) українською.

Дані:
{$json}
TXT;
    }

    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'summary' => ['type' => 'STRING'],
                'proposals' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                    'field' => ['type' => 'STRING'],
                    'new_value' => ['type' => 'STRING', 'nullable' => true],
                    'reason' => ['type' => 'STRING'],
                    'confidence' => ['type' => 'NUMBER'],
                ], 'required' => ['field', 'reason']]],
            ],
            'required' => ['proposals'],
        ];
    }

    /** Демо-режим без ключа: пропозиції з виправлень, які працівник указав у зауваженнях. */
    private function demo(Record $record, $remarks): array
    {
        usleep(1_500_000);
        $proposals = $remarks->filter(fn ($r) => $r->field && $r->suggested_value !== null)->map(fn ($r) => [
            'field' => $r->field, 'new_value' => $r->suggested_value, 'confidence' => 0.9,
            'reason' => 'За зауваженням: '.($r->comment ?: 'виправлення працівника').' (демо-режим без Gemini AI)',
        ])->values()->all();
        $json = ['summary' => $proposals ? 'Демо-режим: перевірено поля із зауважень.' : 'Демо-режим: розбіжностей не знайдено. Для справжньої перевірки додайте GEMINI_API_KEY.', 'proposals' => $proposals];

        return ['json' => $json, 'raw' => json_encode($json, JSON_UNESCAPED_UNICODE), 'model' => 'demo (без Gemini)'];
    }
}
