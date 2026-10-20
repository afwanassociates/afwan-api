<?php

namespace App\Support;

use App\Enums\MedicalResult;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;

/**
 * A passport's history from entry to flight, oldest first: the entry, the
 * medical slip, every medical result and every step record (soft-deleted
 * records left out). Built from the stored records; nothing extra is kept.
 */
class PassportHistory
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * The events, oldest first (same day: in workflow order).
     *
     * @return list<array<string, mixed>>
     */
    public function events(PassportEntry $passport): array
    {
        $passport->loadMissing(['creator', 'medicalCenter', 'medicalRecords.creator', 'stepRecords.creator']);

        $events = [$this->entered($passport)];

        if ($passport->medical_slip_date) {
            $events[] = $this->event(
                key: 'medical-slip',
                date: $passport->medical_slip_date->toDateString(),
                step: 'medical',
                title: 'Medical slip added',
                tone: 'gray',
                details: [['label' => 'Medical center', 'value' => $passport->medicalCenter?->name, 'type' => 'text']],
            );
        }

        foreach ($passport->medicalRecords as $record) {
            $events[] = $this->medical($record);
        }

        foreach ($passport->stepRecords as $record) {
            $events[] = $this->step($record);
        }

        $order = array_flip(array_column($this->workflow->steps(), 'key'));

        usort($events, fn (array $a, array $b) => [$a['date'], $order[$a['step_key']] ?? 99, $a['sequence']]
            <=> [$b['date'], $order[$b['step_key']] ?? 99, $b['sequence']]);

        return array_map(function (array $event) {
            unset($event['sequence']);

            return $event;
        }, $events);
    }

    /**
     * @return array<string, mixed>
     */
    protected function entered(PassportEntry $passport): array
    {
        return $this->event(
            key: 'entered',
            date: $passport->passport_received_date->toDateString(),
            step: Workflow::PASSPORT,
            title: 'Passport entered',
            tone: 'gray',
            details: [
                ['label' => 'Received date', 'value' => $passport->passport_received_date->toDateString(), 'type' => 'date'],
                ['label' => 'Entered on', 'value' => $passport->created_at ? LocalDate::parse($passport->created_at)->toDateString() : null, 'type' => 'date'],
            ],
            by: $passport->creator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function medical(MedicalRecord $record): array
    {
        $fit = $record->result === MedicalResult::Fit;

        return $this->event(
            key: "medical-{$record->id}",
            date: $record->medical_date->toDateString(),
            step: 'medical',
            title: 'Medical '.$record->result->label(),
            tone: $fit ? 'green' : 'red',
            details: [['label' => 'Valid until', 'value' => $record->valid_until?->toDateString(), 'type' => 'date']],
            by: $record->creator,
            remarks: $record->remarks,
            sequence: $record->id,
        );
    }

    /**
     * One step record with its configured fields (dates, visa number, …).
     *
     * @return array<string, mixed>
     */
    protected function step(PassportStepRecord $record): array
    {
        $config = $this->workflow->step($record->step_key) ?? [];
        $details = [];

        foreach ($config['fields'] ?? [] as $field) {
            $value = match (true) {
                $field['in_details'] ?? false => $record->detail($field['name']),
                // A start field (visa submitted) keeps the start date once a result redated the record.
                $field['name'] === 'step_date' => ($field['phase'] ?? null) === 'start' && $record->detail('start_date')
                    ? $record->detail('start_date')
                    : $record->step_date->toDateString(),
                $field['name'] === 'valid_until' => $record->valid_until?->toDateString(),
                $field['name'] === 'reference_no' => $record->reference_no,
                default => null,
            };
            $details[] = ['label' => $field['label'], 'value' => $value, 'type' => $field['type'] ?? 'text'];
        }

        if ($record->reference_no && ! collect($config['fields'] ?? [])->contains('name', 'reference_no')) {
            $details[] = ['label' => 'Reference', 'value' => $record->reference_no, 'type' => 'text'];
        }

        return $this->event(
            key: "step-{$record->id}",
            date: $record->step_date->toDateString(),
            step: $record->step_key,
            title: $record->status->label($record->step_key),
            tone: $this->workflow->tone($record->status->value),
            details: $details,
            by: $record->creator,
            remarks: $record->remarks,
            sequence: $record->id,
        );
    }

    /**
     * @param  list<array{label: string, value: mixed, type: string}>  $details
     * @return array<string, mixed>
     */
    protected function event(
        string $key,
        string $date,
        string $step,
        string $title,
        string $tone,
        array $details = [],
        ?User $by = null,
        ?string $remarks = null,
        int $sequence = 0,
    ): array {
        return [
            'key' => $key,
            'date' => $date,
            'step_key' => $step,
            'step_label' => $this->workflow->label($step),
            'title' => $title,
            'tone' => $tone,
            // Only filled fields.
            'details' => array_values(array_filter($details, fn (array $d) => $d['value'] !== null && $d['value'] !== '')),
            'recorded_by' => $by ? ['id' => $by->id, 'name' => $by->name] : null,
            'remarks' => $remarks,
            'sequence' => $sequence,
        ];
    }
}
