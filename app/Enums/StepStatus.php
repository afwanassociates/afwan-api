<?php

namespace App\Enums;

/**
 * The status of a workflow step record (calling, visa, BMET, flight). Each
 * step names its statuses itself in config/workflow.php, e.g. a completed
 * flight is "Departed".
 */
enum StepStatus: string
{
    case InProcess = 'in_process';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * The step's label for this status, e.g. "Visa issued" for a completed visa.
     */
    public function label(string $stepKey): string
    {
        $steps = collect(config('workflow.steps', []))->keyBy('key');

        return (string) ($steps[$stepKey]['statuses'][$this->value] ?? str($this->value)->replace('_', ' ')->title());
    }

    /**
     * All status values as strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
