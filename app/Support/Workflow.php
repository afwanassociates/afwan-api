<?php

namespace App\Support;

use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Models\PassportEntry;

/**
 * The passport workflow step bar (config/workflow.php).
 *
 * Step states: passport is done or needs_attention (entry incomplete);
 * medical is pending, passed, failed or expired; any later step is locked
 * while disabled and, once enabled, ready when the step before it is done.
 */
class Workflow
{
    /**
     * States that count as a finished step.
     */
    public const DONE_STATES = ['done', 'passed'];

    /**
     * The configured steps, in order.
     *
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public function steps(): array
    {
        return array_map(fn (array $step) => [
            'key' => (string) $step['key'],
            'label' => (string) $step['label'],
            'enabled' => (bool) ($step['enabled'] ?? true),
        ], array_values(config('workflow.steps', [])));
    }

    /**
     * Whether the step with the given key is switched on.
     */
    public function enabled(string $key): bool
    {
        foreach ($this->steps() as $step) {
            if ($step['key'] === $key) {
                return $step['enabled'];
            }
        }

        return false;
    }

    /**
     * Each step's state for one passport, and the first enabled step that is
     * not done (null once every enabled step is done).
     *
     * @return array{steps: list<array{key: string, label: string, state: string, enabled: bool}>, current_step: string|null}
     */
    public function forPassport(PassportEntry $passport, MedicalStatus $medicalStatus): array
    {
        $steps = [];
        $previousDone = true;
        $currentStep = null;

        foreach ($this->steps() as $step) {
            $state = match (true) {
                $step['key'] === 'passport' => $passport->isIncomplete() ? 'needs_attention' : 'done',
                $step['key'] === 'medical' => match ($medicalStatus) {
                    MedicalStatus::Pending => 'pending',
                    MedicalStatus::Fit, MedicalStatus::ExpiringSoon => 'passed',
                    MedicalStatus::Unfit => 'failed',
                    MedicalStatus::Expired => 'expired',
                },
                ! $step['enabled'] => 'locked',
                default => $previousDone ? 'ready' : 'locked',
            };

            $done = in_array($state, self::DONE_STATES, true);

            if ($currentStep === null && $step['enabled'] && ! $done) {
                $currentStep = $step['key'];
            }

            $previousDone = $done;
            $steps[] = [...$step, 'state' => $state];
        }

        return ['steps' => $steps, 'current_step' => $currentStep];
    }

    /**
     * One "latest status" for a passport, for the All Passports overview:
     * pending or unfit at the medical step, otherwise waiting at the step
     * after medical. A fit medical that has run out (worked out today, never
     * stored) reads "Medical: Expired" at that same step. The date is the
     * stored stage_status_date (load currentMedical first to avoid a query).
     *
     * @return array{stage: string, stage_label: string, status: string, status_label: string, text: string, tone: string, date: string|null}
     */
    public function latestStatus(PassportEntry $passport): array
    {
        $medical = $this->step('medical');
        $next = $this->stepAfter('medical') ?? $medical;

        [$stage, $status, $textStage] = match ($passport->medicalStatus()) {
            MedicalStatus::Pending => [$medical, 'pending', $medical],
            MedicalStatus::Unfit => [$medical, 'unfit', $medical],
            MedicalStatus::Fit, MedicalStatus::ExpiringSoon => [$next, 'waiting', $next],
            MedicalStatus::Expired => [$next, 'expired', $medical],
        };

        $statusLabel = $this->statusLabel($status);

        return [
            'stage' => $stage['key'],
            'stage_label' => $stage['label'],
            'status' => $status,
            'status_label' => $statusLabel,
            'text' => "{$textStage['label']}: {$statusLabel}",
            'tone' => $this->tone($status),
            'date' => $passport->stage_status_date?->toDateString(),
        ];
    }

    /**
     * The configured label of a status, or the status itself made readable.
     */
    public function statusLabel(string $status): string
    {
        return (string) (config("workflow.status_labels.{$status}") ?? str($status)->replace('_', ' ')->title());
    }

    /**
     * The colour for a status (config workflow.tones), gray when unknown.
     */
    public function tone(string $status): string
    {
        return (string) (config("workflow.tones.{$status}") ?? config('workflow.default_tone', 'gray'));
    }

    /**
     * The configured step with the given key.
     *
     * @return array{key: string, label: string, enabled: bool}
     */
    protected function step(string $key): array
    {
        foreach ($this->steps() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return ['key' => $key, 'label' => str($key)->title()->toString(), 'enabled' => false];
    }

    /**
     * The step configured right after the given one, if any.
     *
     * @return array{key: string, label: string, enabled: bool}|null
     */
    protected function stepAfter(string $key): ?array
    {
        $steps = $this->steps();
        $index = array_search($key, array_column($steps, 'key'), true);

        return $index === false ? null : ($steps[$index + 1] ?? null);
    }

    /**
     * Counts for the step bar, from one aggregate query over all passports.
     * Unfit passports are not "active" but are in total_all; fit includes
     * expiring_soon. Soft-deleted passports are never counted.
     *
     * @return array{step1: array{total_all: int, total_active: int, incomplete: int}, step2: array<string, int>, step3: array{enabled: bool, ready: int}}
     */
    public function summary(): array
    {
        $today = LocalDate::today()->toDateString();
        $soon = MedicalStatus::expiringSoonLimit(LocalDate::today())->toDateString();
        $fit = MedicalResult::Fit->value;
        $unfit = MedicalResult::Unfit->value;
        $m = PassportEntry::CURRENT_MEDICAL;

        $notUnfit = "({$m}.id is null or {$m}.result <> '{$unfit}')";
        $incomplete = implode(' or ', array_map(
            fn (string $field) => "passport_entries.{$field} is null",
            PassportEntry::COMPLETENESS_FIELDS,
        ));

        $row = PassportEntry::query()
            ->withCurrentMedical()
            ->toBase()
            ->select([])
            ->selectRaw(implode(', ', [
                'count(*) as total_all',
                "coalesce(sum({$notUnfit}), 0) as total_active",
                "coalesce(sum({$notUnfit} and ({$incomplete})), 0) as incomplete",
                "coalesce(sum({$m}.id is null), 0) as pending",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until >= ?), 0) as fit",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until between ? and ?), 0) as expiring_soon",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until < ?), 0) as expired",
                "coalesce(sum({$m}.result = '{$unfit}'), 0) as unfit",
            ]), [$today, $today, $soon, $today])
            ->first();

        // Later steps become ready once the medical is passed (fit and valid).
        $step3Enabled = $this->enabled('step3');

        return [
            'step1' => [
                'total_all' => (int) $row->total_all,
                'total_active' => (int) $row->total_active,
                'incomplete' => (int) $row->incomplete,
            ],
            'step2' => [
                'pending' => (int) $row->pending,
                'fit' => (int) $row->fit,
                'expiring_soon' => (int) $row->expiring_soon,
                'expired' => (int) $row->expired,
                'unfit' => (int) $row->unfit,
            ],
            'step3' => [
                'enabled' => $step3Enabled,
                'ready' => $step3Enabled ? (int) $row->fit : 0,
            ],
        ];
    }
}
