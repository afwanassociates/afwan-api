<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CompanyPassportsRequest;
use App\Http\Requests\Reports\CompanyReportRequest;
use App\Http\Resources\CompanyPassportResource;
use App\Http\Resources\PassportEntryResource;
use App\Http\Resources\PassportOverviewResource;
use App\Models\Company;
use App\Models\Reference;
use App\Support\CompanyReport;
use App\Support\LocalDate;
use App\Support\PassportOverviewCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyReportController extends Controller
{
    public function __construct(protected CompanyReport $report) {}

    /**
     * One row per company with its passport counts (paginated, with totals),
     * or the whole report as a CSV download with export=csv.
     */
    public function index(CompanyReportRequest $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewReport', Company::class);

        $query = $this->report->query($request->validated());

        if ($request->validated('export') === 'csv') {
            return $this->csv($query);
        }

        $page = $query->paginate($request->integer('per_page', 50))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Company $company) => $this->report->row($company))->all(),
            'totals' => $this->report->totals($this->report->query($request->validated())),
            // The chosen company / reference, so the filters can show their names after a reload.
            'filters' => [
                'company' => $this->filterCompany($request->validated('company_id')),
                'reference' => $this->filterReference($request->validated('reference_id')),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * A company's passports, every medical status included (paginated), in
     * the passport list's shape with latest_status. export=csv downloads every
     * one of them (same filters) in the All Passports CSV's columns.
     */
    public function passports(CompanyPassportsRequest $request, Company $company, PassportOverviewCsv $csv): AnonymousResourceCollection|StreamedResponse
    {
        Gate::authorize('viewReport', Company::class);

        $export = $request->validated('export') === 'csv';

        $passports = $company->passportEntries()
            ->with($export ? PassportOverviewResource::RELATIONS : PassportEntryResource::RELATIONS)
            ->when($request->has('stage'), fn (Builder $query) => $query->where('current_stage', $request->validated('stage')))
            ->when($request->has('stage_status'), fn (Builder $query) => $query->where('stage_status', $request->validated('stage_status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($export) {
            return $csv->download($passports, 'company-'.(Str::slug($company->name) ?: $company->id).'-passports');
        }

        $passports = $passports
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return CompanyPassportResource::collection($passports);
    }

    /**
     * Stream the report as UTF-8 CSV (with a BOM so Excel reads it right), a
     * totals row last, and the date in the file name as DD-MM-YYYY.
     *
     * @param  Builder<Company>  $query
     */
    protected function csv(Builder $query): StreamedResponse
    {
        $countColumns = $this->report->countColumns();
        $totals = $this->report->totals(clone $query);
        $filename = 'company-report-'.LocalDate::today()->format('d-m-Y').'.csv';

        return response()->streamDownload(function () use ($query, $countColumns, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'Company', 'Country', 'Quota',
                ...array_map(fn (string $column) => str($column)->replace('_', ' ')->ucfirst()->toString(), $countColumns),
                'Balance',
            ], escape: '');

            foreach ($query->lazy() as $company) {
                $row = $this->report->row($company);

                fputcsv($out, [
                    $row['id'],
                    $this->cell($row['name']),
                    $row['country_code'],
                    $row['quota'],
                    ...array_map(fn (string $column) => $row[$column], $countColumns),
                    $row['balance'],
                ], escape: '');
            }

            fputcsv($out, [
                'Total', '', '', $totals['quota'],
                ...array_map(fn (string $column) => $totals[$column], $countColumns),
                $totals['balance'],
            ], escape: '');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{id: int, name: string, country_code: string}|null
     */
    protected function filterCompany(int|string|null $id): ?array
    {
        $company = $id === null ? null : Company::withTrashed()->find($id);

        return $company === null ? null : ['id' => $company->id, 'name' => $company->name, 'country_code' => $company->country_code];
    }

    /**
     * @return array{id: int, name: string, type: string, type_label: string, phone: string|null}|null
     */
    protected function filterReference(int|string|null $id): ?array
    {
        $reference = $id === null ? null : Reference::withTrashed()->find($id);

        return $reference === null ? null : [
            'id' => $reference->id,
            'name' => $reference->name,
            'type' => $reference->type->value,
            'type_label' => $reference->type->label(),
            'phone' => $reference->phone,
        ];
    }

    /**
     * Stop spreadsheet apps from running a text cell as a formula.
     */
    protected function cell(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
    }
}
