<?php

namespace App\Support;

use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The passport workflow (config/workflow.php): passport, medical, then the
 * record steps (calling, visa, bmet, flight), strictly in order.
 *
 * Reads only: the stored position (current_stage, stage_status) is written by
 * App\Services\WorkflowService::recompute. Step states: passport is done or
 * needs_attention; medical is pending, passed, failed or expired; a record
 * step is completed, waiting, in_process, rejected or locked.
 */
class Workflow
{
    /**
     * The stage of a passport whose medical has not started (no slip date yet).
     */
    public const PASSPORT = 'passport';

    /**
     * The stage of a passport whose every step is completed.
     */
    public const COMPLETED = 'completed';

    /**
     * Values of passport_entries.stage_status.
     *
     * @var list<string>
     */
    public const STAGE_STATUSES = ['not_started', 'pending', 'unfit', 'waiting', 'in_process', 'rejected'];

    /**
     * The configured steps, in order, with their definitions.
     *
     * @return list<array{key: string, label: string, short_label: string, order: int, enabled: bool, statuses: array<string, string>, fields: list<array<string, mixed>>, warning_days: int|null}>
     */
    public function steps(): array
    {
        return array_values(array_map(fn (array $step, int $index) => [
            'key' => (string) $step['key'],
            'label' => (string) $step['label'],
            'short_label' => (string) ($step['short_label'] ?? $step['label']),
            'order' => $index + 1,
            'enabled' => (bool) ($step['enabled'] ?? true),
            'statuses' => $step['statuses'] ?? [],
            'fields' => $step['fields'] ?? [],
            'warning_days' => isset($step['warning_days']) ? (int) $step['warning_days'] : null,
        ], array_values(config('workflow.steps', [])), array_keys(array_values(config('workflow.steps', [])))));
    }

    /**
     * The steps as a compact list for the step bar.
     *
     * @return list<array{key: string, label: string, short_label: string, order: int, enabled: bool}>
     */
    public function stepList(): array
    {
        return array_map(fn (array $step) => [
            'key' => $step['key'],
            'label' => $step['label'],
            'short_label' => $step['short_label'],
            'order' => $step['order'],
            'enabled' => $step['enabled'],
        ], $this->steps());
    }

    /**
     * The configured step with the given key.
     *
     * @return array{key: string, label: string, short_label: string, order: int, enabled: bool, statuses: array<string, string>, fields: list<array<string, mixed>>, warning_days: int|null}|null
     */
    public function step(string $key): ?array
    {
        foreach ($this->steps() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The label of a step (the key itself when it is not configured).
     */
    public function label(string $key): string
    {
        return $this->step($key)['label'] ?? str($key)->title()->toString();
    }

    /**
     * Keys of the enabled steps after medical, which keep step records, in order.
     *
     * @return list<string>
     */
    public function recordStepKeys(): array
    {
        $keys = [];
        $afterMedical = false;

        foreach ($this->steps() as $step) {
            if ($afterMedical && $step['enabled']) {
                $keys[] = $step['key'];
            }

            $afterMedical = $afterMedical || $step['key'] === 'medical';
        }

        return $keys;
    }

    /**
     * Every value of passport_entries.current_stage.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        return [self::PASSPORT, 'medical', ...$this->recordStepKeys(), self::COMPLETED];
    }

    /**
     * The record steps before / after the given one.
     *
     * @return list<string>
     */
    public function recordStepsBefore(string $key): array
    {
        $keys = $this->recordStepKeys();

        return array_slice($keys, 0, (int) array_search($key, $keys, true));
    }

    /**
     * @return list<string>
     */
    public function recordStepsAfter(string $key): array
    {
        $keys = $this->recordStepKeys();
        $index = array_search($key, $keys, true);

        return $index === false ? [] : array_slice($keys, $index + 1);
    }

    /**
     * Whether a later step than $key has any record, read from the stored
     * stage (no query): the stage is past the next step, or at it with a record.
     */
    public function hasRecordsAfter(PassportEntry $passport, string $key): bool
    {
        $stages = $this->stages();
        $next = $this->recordStepsAfter($key)[0] ?? null;
        $stageIndex = array_search($passport->current_stage, $stages, true);

        if ($next === null || $stageIndex === false) {
            return false;
        }

        $nextIndex = array_search($next, $stages, true);

        return $stageIndex > $nextIndex || ($stageIndex === $nextIndex && $passport->stage_status !== 'waiting');
    }

    /**
     * The latest record of each step, keyed by step key, from the passport's
     * stepRecords (eager load them to avoid a query).
     *
     * @return Collection<string, PassportStepRecord>
     */
    public function latestRecords(PassportEntry $passport): Collection
    {
        return $passport->stepRecords
            ->sortByDesc(fn (PassportStepRecord $record) => [$record->step_date->toDateString(), $record->id])
            ->unique('step_key')
            ->keyBy('step_key');
    }

    /**
     * Each step's state for one passport, with the latest record of the
     * record steps, and the current step (null once completed). Load
     * currentMedical and stepRecords.creator first to avoid queries.
     *
     * @return array{steps: list<array{key: string, label: string, state: string, record: array<string, mixed>|null, enabled: bool}>, current_step: string|null}
     */
    public function forPassport(PassportEntry $passport, ?MedicalStatus $medicalStatus = null): array
    {
        $medicalStatus ??= $passport->medicalStatus();
        $latest = $this->latestRecords($passport);
        $stages = $this->stages();
        $stageIndex = array_search($passport->current_stage ?? self::PASSPORT, $stages, true);

        $steps = [];

        foreach ($this->steps() as $step) {
            $index = array_search($step['key'], $stages, true);

            $state = match (true) {
                $step['key'] === 'passport' => $passport->isIncomplete() ? 'needs_attention' : 'done',
                $step['key'] === 'medical' => match ($medicalStatus) {
                    MedicalStatus::NotStarted => 'not_started',
                    MedicalStatus::Pending => 'pending',
                    MedicalStatus::Fit, MedicalStatus::ExpiringSoon => 'passed',
                    MedicalStatus::Unfit => 'failed',
                    MedicalStatus::Expired => 'expired',
                },
                $index === false => 'locked',
                $index < $stageIndex => 'completed',
                $index === $stageIndex => (string) $passport->stage_status,
                default => 'locked',
            };

            $record = $latest->get($step['key']);

            $steps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'record' => $record ? $this->recordSummary($record) : null,
                'enabled' => $step['enabled'],
            ];
        }

        $currentStep = match (true) {
            $passport->isIncomplete() => 'passport',
            $passport->current_stage === self::COMPLETED => null,
            default => $passport->current_stage ?? self::PASSPORT,
        };

        return ['steps' => $steps, 'current_step' => $currentStep];
    }

    /**
     * The fields shown wherever a step record appears (load creator first).
     *
     * @return array<string, mixed>
     */
    public function recordSummary(PassportStepRecord $record): array
    {
        return [
            'id' => $record->id,
            'status' => $record->status->value,
            'status_label' => $record->status->label($record->step_key),
            'reference_no' => $record->reference_no,
            'step_date' => $record->step_date->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'validity_status' => $this->validityStatus($record),
            'days_left' => $this->daysLeft($record),
            'details' => $record->details,
            'remarks' => $record->remarks,
            'recorded_by' => $record->creator ? [
                'id' => $record->creator->id,
                'name' => $record->creator->name,
            ] : null,
        ];
    }

    /**
     * valid, expiring_soon (within the step's warning_days, inclusive) or
     * expired, by today's Dhaka date; null when the record has no valid_until.
     */
    public function validityStatus(PassportStepRecord $record): ?string
    {
        $daysLeft = $this->daysLeft($record);

        return match (true) {
            $daysLeft === null => null,
            $daysLeft < 0 => 'expired',
            $daysLeft <= (int) ($this->step($record->step_key)['warning_days'] ?? 0) => 'expiring_soon',
            default => 'valid',
        };
    }

    /**
     * Days from today (Dhaka) until valid_until; negative once passed.
     */
    public function daysLeft(PassportStepRecord $record): ?int
    {
        return $record->valid_until === null
            ? null
            : (int) LocalDate::today()->diffInDays(LocalDate::parse($record->valid_until), false);
    }

    /**
     * Non-blocking warnings about dates (load currentMedical and stepRecords
     * first). Messages never contain the dates or numbers themselves.
     *
     * @return list<array{code: string, message: string}>
     */
    public function warnings(PassportEntry $passport): array
    {
        $warnings = [];
        $latest = $this->latestRecords($passport);
        $today = LocalDate::today();
        $months = (int) config('workflow.passport_expiry_warning_months', 6);
        $expiry = $passport->passport_expiry_date ? LocalDate::parse($passport->passport_expiry_date) : null;
        $departure = $this->departureDate($latest->get('flight'));

        if ($expiry !== null && $departure !== null) {
            if ($expiry->lt($departure->addMonthsNoOverflow($months))) {
                $warnings[] = [
                    'code' => 'passport_expires_soon_after_departure',
                    'message' => "The passport expires less than {$months} months after the flight departure date.",
                ];
            }
        } elseif ($expiry !== null && ($latest->has('visa') || $latest->has('bmet')) && $expiry->lt($today->addMonthsNoOverflow($months))) {
            $warnings[] = [
                'code' => 'passport_expires_soon',
                'message' => "The passport expires in less than {$months} months.",
            ];
        }

        $visaValidUntil = $latest->get('visa')?->valid_until;

        if ($visaValidUntil !== null && $departure !== null && LocalDate::parse($visaValidUntil)->lt($departure)) {
            $warnings[] = [
                'code' => 'visa_expires_before_departure',
                'message' => 'The visa is valid only until before the flight departure date.',
            ];
        }

        if ($passport->medicalStatus() === MedicalStatus::Expired
            && in_array($passport->current_stage, $this->recordStepKeys(), true)) {
            $warnings[] = [
                'code' => 'medical_expired',
                'message' => 'The medical has expired while the passport is at a later step.',
            ];
        }

        return $warnings;
    }

    /**
     * The flight's departure date, from its details.
     */
    public function departureDate(?PassportStepRecord $flight): ?CarbonImmutable
    {
        $date = $flight?->detail('departure_date');

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? LocalDate::parse($date) : null;
    }

    /**
     * One "latest status" for the All Passports overview, from the stored
     * stage: "<step label>: <status label>" (e.g. "Visa: Submitted"), and
     * "Completed: Departed" at the end. Only "Medical: Expired" (a fit medical
     * that ran out while waiting for calling) is worked out today, never
     * stored. Load currentMedical first to avoid a query.
     *
     * @return array{stage: string, stage_label: string, status: string, status_label: string, text: string, tone: string, date: string|null}
     */
    public function latestStatus(PassportEntry $passport): array
    {
        $stage = (string) ($passport->current_stage ?? self::PASSPORT);
        $status = (string) $passport->stage_status;
        $textStage = $this->label($stage);
        $lastStep = $this->recordStepKeys()[count($this->recordStepKeys()) - 1] ?? null;

        if ($stage === self::COMPLETED) {
            $status = StepStatus::Completed->value;
            $statusLabel = $lastStep ? StepStatus::Completed->label($lastStep) : $this->statusLabel($status);
            $textStage = $this->statusLabel(self::COMPLETED);
        } elseif ($stage === ($this->recordStepKeys()[0] ?? null) && $status === 'waiting'
            && $passport->medicalStatus() === MedicalStatus::Expired) {
            $status = 'expired';
            $statusLabel = $this->statusLabel($status);
            $textStage = $this->label('medical');
        } else {
            $stepStatus = StepStatus::tryFrom($status);
            $statusLabel = $stepStatus ? $stepStatus->label($stage) : $this->statusLabel($status);
        }

        return [
            'stage' => $stage,
            'stage_label' => $stage === self::COMPLETED ? $this->statusLabel(self::COMPLETED) : $this->label($stage),
            'status' => $status,
            'status_label' => $statusLabel,
            'text' => "{$textStage}: {$statusLabel}",
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
     * The step definitions the SPA builds its forms from.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(function (array $step) {
            $statuses = match ($step['key']) {
                'passport' => [],
                'medical' => array_map(fn (MedicalResult $result) => ['value' => $result->value, 'label' => $result->label()], MedicalResult::cases()),
                default => array_map(fn (StepStatus $status) => ['value' => $status->value, 'label' => $status->label($step['key'])], StepStatus::cases()),
            };

            return [
                'key' => $step['key'],
                'label' => $step['label'],
                'short_label' => $step['short_label'],
                'order' => $step['order'],
                'enabled' => $step['enabled'],
                'statuses' => $statuses,
                'fields' => array_map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'max' => $field['max'] ?? null,
                    'in_details' => (bool) ($field['in_details'] ?? false),
                    'required_for' => $field['required_for'] ?? [],
                ], $step['fields']),
                'warning_days' => $step['warning_days'],
            ];
        }, $this->steps());
    }

    /**
     * Counts for the step bar. One aggregate query over passports and current
     * medicals, plus one grouped query for the stages. Unfit passports are not
     * "active" but are in total_all; fit includes expiring_soon. Soft-deleted
     * passports are never counted.
     *
     * @return array<string, mixed>
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
                "coalesce(sum({$m}.id is null and passport_entries.medical_slip_date is null), 0) as not_started",
                "coalesce(sum({$m}.id is null and passport_entries.medical_slip_date is not null), 0) as pending",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until >= ?), 0) as fit",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until between ? and ?), 0) as expiring_soon",
                "coalesce(sum({$m}.result = '{$fit}' and {$m}.valid_until < ?), 0) as expired",
                "coalesce(sum({$m}.result = '{$unfit}'), 0) as unfit",
            ]), [$today, $today, $soon, $today])
            ->first();

        $counts = PassportEntry::query()
            ->toBase()
            ->select(['current_stage', 'stage_status'])
            ->selectRaw('count(*) as total')
            ->groupBy('current_stage', 'stage_status')
            ->get();

        $stages = [];

        foreach ([self::PASSPORT, 'medical', ...$this->recordStepKeys()] as $stage) {
            $statuses = match ($stage) {
                self::PASSPORT => ['not_started'],
                'medical' => ['pending', 'unfit'],
                default => ['waiting', 'in_process', 'rejected'],
            };
            $rows = $counts->where('current_stage', $stage);

            $stages[$stage] = [
                'total' => (int) $rows->sum('total'),
                ...array_combine($statuses, array_map(
                    fn (string $status) => (int) $rows->where('stage_status', $status)->sum('total'),
                    $statuses,
                )),
            ];
        }

        $calling = $this->recordStepKeys()[0] ?? null;

        return [
            'step1' => [
                'total_all' => (int) $row->total_all,
                'total_active' => (int) $row->total_active,
                'incomplete' => (int) $row->incomplete,
            ],
            'step2' => [
                'not_started' => (int) $row->not_started,
                'pending' => (int) $row->pending,
                'fit' => (int) $row->fit,
                'expiring_soon' => (int) $row->expiring_soon,
                'expired' => (int) $row->expired,
                'unfit' => (int) $row->unfit,
            ],
            // The step after medical: passports waiting for it to be recorded.
            'step3' => [
                'enabled' => $calling !== null,
                'ready' => $calling ? $stages[$calling]['waiting'] : 0,
            ],
            'stages' => $stages,
            'completed' => (int) $counts->where('current_stage', self::COMPLETED)->sum('total'),
        ];
    }
}
