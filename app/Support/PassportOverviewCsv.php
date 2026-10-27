<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PassportEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Passports as a CSV download (the All Passports overview, a company's
 * passports): the rows of the list (same searches and order, every page),
 * UTF-8 with a BOM so Excel reads it right, dates as DD-MM-YYYY and the date
 * in the file name. Load PassportOverviewResource::RELATIONS first.
 */
class PassportOverviewCsv
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * @param  Builder<PassportEntry>|HasMany<PassportEntry, Company>  $query
     */
    public function download(Builder|HasMany $query, string $prefix = 'all-passports'): StreamedResponse
    {
        $filename = $prefix.'-'.LocalDate::today()->format('d-m-Y').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'No.', 'Passport name', 'Passport number', 'Date of birth', 'Country', 'Passport expiry date',
                'Company', 'Company country', 'Reference', 'Received date', 'Entered by', 'Latest status',
            ], escape: '');

            foreach ($query->lazy() as $passport) {
                fputcsv($out, array_map(fn (mixed $value) => $this->cell($value), [
                    $passport->entry_no,
                    $passport->passport_name,
                    $passport->passport_number,
                    $passport->date_of_birth?->format('d-m-Y'),
                    $passport->country?->name,
                    $passport->passport_expiry_date?->format('d-m-Y'),
                    $passport->company->name,
                    $passport->company->country?->name,
                    $passport->reference?->name,
                    $passport->passport_received_date->format('d-m-Y'),
                    $passport->creator?->name,
                    $this->workflow->latestStatus($passport)['text'] ?? null,
                ]), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Stop spreadsheet apps from running a text cell as a formula.
     */
    protected function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
    }
}
