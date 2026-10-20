<?php

namespace App\Support;

use App\Http\Resources\StepListResource;
use App\Models\PassportEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A step page's tab as a CSV download: the rows of the list (same tab,
 * filters and order), UTF-8 with a BOM so Excel reads it right, dates as
 * DD-MM-YYYY and the date in the file name.
 */
class StepListCsv
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * @param  Builder<PassportEntry>  $query
     */
    public function download(Builder $query, string $step, string $tab, Request $request): StreamedResponse
    {
        $columns = $this->columns($step);
        $filename = "{$step}-{$tab}-".LocalDate::today()->format('d-m-Y').'.csv';

        return response()->streamDownload(function () use ($query, $step, $columns, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns), escape: '');

            foreach ($query->lazy() as $passport) {
                $row = (new StepListResource($passport))->forStep($step)->toArray($request);

                fputcsv($out, array_map(fn (callable $value) => $this->cell($value($row)), $columns), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Column title => value from a list row: the passport, then this step's
     * own dates.
     *
     * @return array<string, callable(array<string, mixed>): mixed>
     */
    protected function columns(string $step): array
    {
        $record = fn (string $key) => fn (array $row) => $row['record'][$key] ?? null;
        $flight = fn (string $key) => fn (array $row) => $row['flight_status'][$key] ?? null;

        return [
            'Passport name' => fn (array $row) => $row['passport_name'],
            'Passport number' => fn (array $row) => $row['passport_number'],
            'Company' => fn (array $row) => $row['company']['name'] ?? null,
            'Reference' => fn (array $row) => $row['reference']['name'] ?? null,
            'Status' => $record('status_label'),
            ...match ($step) {
                'calling' => [
                    'Receive date' => $record('receive_date'),
                    'Issued date' => $record('issue_date'),
                    'Expired date' => $record('valid_until'),
                ],
                'visa' => [
                    'Visa submitted date' => $record('submitted_date'),
                    'Issue date' => $record('issue_date'),
                    'Expired date' => $record('valid_until'),
                    'Visa number' => $record('reference_no'),
                ],
                'bmet' => [
                    'Visa issue date' => fn (array $row) => $row['visa_issue_date'] ?? null,
                    'Visa expired date' => fn (array $row) => $row['visa_valid_until'] ?? null,
                    'Training date' => fn (array $row) => $row['training_date'] ?? null,
                    'Finger date' => fn (array $row) => $row['finger_date'] ?? null,
                    'Clearance date' => fn (array $row) => $row['clearance_date'] ?? null,
                ],
                'flight' => [
                    'Flight date' => $flight('booking_date'),
                    'Confirmed date' => $flight('confirmed_date'),
                ],
                default => ['Date' => $record('step_date')],
            },
        ];
    }

    /**
     * Dates as DD-MM-YYYY; text cells cannot start a spreadsheet formula.
     */
    protected function cell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return LocalDate::parse($value)->format('d-m-Y');
        }

        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
    }
}
