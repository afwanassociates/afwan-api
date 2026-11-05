<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReturnedPassportsRequest;
use App\Http\Resources\ReturnedPassportResource;
use App\Models\PassportEntry;
use App\Models\PassportReturn;
use App\Support\Like;
use App\Support\LocalDate;
use App\Support\Normalize;
use App\Support\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnedPassportController extends Controller
{
    /**
     * The returns in force (undone ones are only in the passport history),
     * newest return first. export=csv downloads every row with the same filters.
     */
    public function __invoke(ReturnedPassportsRequest $request, Workflow $workflow): AnonymousResourceCollection|StreamedResponse
    {
        Gate::authorize('viewReturned', PassportEntry::class);

        $search = trim((string) $request->validated('q'));

        $query = PassportReturn::query()
            ->with(ReturnedPassportResource::RELATIONS)
            ->whereNull('undone_at')
            ->whereHas('passportEntry', fn (Builder $query) => $query
                ->whereColumn('passport_entries.current_return_id', 'passport_returns.id')
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('passport_name', 'like', Like::contains($search))
                    ->orWhere('passport_number', 'like', Like::contains((string) Normalize::passportNumber($search)))))
                ->when($request->validated('company_id'), fn (Builder $query, int|string $id) => $query->where('company_id', (int) $id))
                ->when($request->validated('reference_id'), fn (Builder $query, int|string $id) => $query->where('reference_id', (int) $id)))
            ->when($request->validated('date_from'), fn (Builder $query, string $date) => $query->where('return_date', '>=', $date))
            ->when($request->validated('date_to'), fn (Builder $query, string $date) => $query->where('return_date', '<=', $date))
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        if ($request->validated('export') === 'csv') {
            return $this->csv($query, $workflow);
        }

        return ReturnedPassportResource::collection(
            $query->paginate($request->integer('per_page', 15))->withQueryString(),
        );
    }

    /**
     * @param  Builder<PassportReturn>  $query
     */
    protected function csv(Builder $query, Workflow $workflow): StreamedResponse
    {
        $filename = 'returned-passports-'.LocalDate::today()->format('d-m-Y').'.csv';
        $cell = fn (mixed $value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;

        return response()->streamDownload(function () use ($query, $workflow, $cell) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'No.', 'Passport name', 'Passport number', 'Reference', 'Company', 'Was at', 'Return date',
                'Receiver name', 'Receiver phone', 'Receiver NID / Passport no.', 'Remarks', 'Returned by',
            ], escape: '');

            foreach ($query->lazy() as $return) {
                $passport = $return->passportEntry;

                fputcsv($out, array_map($cell, [
                    $passport->entry_no,
                    $passport->passport_name,
                    $passport->passport_number,
                    $passport->reference?->name,
                    $passport->company?->name,
                    ReturnedPassportResource::stageLabel($workflow, $return->stage),
                    $return->return_date->format('d-m-Y'),
                    $return->receiver_name,
                    $return->receiver_phone,
                    $return->receiver_id_no,
                    $return->remarks,
                    $return->returner?->name,
                ]), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
