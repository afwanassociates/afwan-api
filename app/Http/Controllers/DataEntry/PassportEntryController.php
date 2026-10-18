<?php

namespace App\Http\Controllers\DataEntry;

use App\Enums\MedicalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListPassportEntriesRequest;
use App\Http\Requests\DataEntry\StorePassportEntryRequest;
use App\Http\Requests\DataEntry\UpdatePassportEntryRequest;
use App\Http\Resources\PassportEntryResource;
use App\Http\Resources\PassportListResource;
use App\Http\Resources\PassportOverviewResource;
use App\Models\PassportEntry;
use App\Services\PassportScanService;
use App\Services\WorkflowService;
use App\Support\Like;
use App\Support\Normalize;
use App\Support\PassportOverviewCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PassportEntryController extends Controller
{
    /**
     * Paginated, searchable list of passport entries. view=overview returns the
     * lean All Passports overview instead of the full entries; mode=passport_entered
     * keeps only passports whose medical has not started (the Passport List page);
     * mode=medical shows one Medical page tab (medical_status), Pending by slip
     * date oldest first and the other tabs newest first, ignoring sort.
     */
    public function index(ListPassportEntriesRequest $request, PassportOverviewCsv $csv): AnonymousResourceCollection|StreamedResponse
    {
        // The read-only overview is open to accounts too; every other list is not.
        Gate::authorize($request->isOverview() ? 'viewOverview' : 'viewAny', PassportEntry::class);

        $search = trim((string) $request->validated('q'));
        $reference = trim((string) $request->validated('reference'));
        $company = trim((string) $request->validated('company'));
        // The overview ignores sort and direction (they are not validated there).
        $sort = $request->validated('sort', 'created_at');
        $direction = $request->validated('direction', 'desc');
        // mode=passport_entered lists only passports whose medical has not
        // started (no slip date, no result; so never unfit).
        $mode = $request->validated('mode');
        $medicalStatus = match ($mode) {
            ListPassportEntriesRequest::PASSPORT_ENTERED => MedicalStatus::NotStarted->value,
            // The Medical page shows one tab at a time, pending by default.
            ListPassportEntriesRequest::MEDICAL => $request->validated('medical_status', MedicalStatus::Pending->value),
            default => $request->validated('medical_status'),
        };
        $overview = $request->isOverview();

        // Columns are qualified: the current medical record is joined in.
        $sortColumn = match ($sort) {
            'medical_date', 'valid_until' => PassportEntry::CURRENT_MEDICAL.".{$sort}",
            'status_date' => 'passport_entries.stage_status_date',
            default => "passport_entries.{$sort}",
        };

        // The Medical page's Pending tab: longest waiting since the slip first.
        if ($mode === ListPassportEntriesRequest::MEDICAL && $medicalStatus === MedicalStatus::Pending->value) {
            [$sortColumn, $direction] = ['passport_entries.medical_slip_date', 'asc'];
        }

        $entries = PassportEntry::query()
            ->with($overview ? PassportOverviewResource::RELATIONS : PassportEntryResource::RELATIONS)
            ->withCurrentMedical()
            // Unfit passports have their own list (medical_status=unfit) and are
            // left out by default; medical_status=all applies no medical filter.
            ->when(
                $medicalStatus === null,
                fn (Builder $query) => $query->withoutUnfit(),
                fn (Builder $query) => $query->when(
                    $medicalStatus !== ListPassportEntriesRequest::ALL_MEDICAL_STATUSES,
                    fn (Builder $query) => $query->whereMedicalStatus(MedicalStatus::from($medicalStatus)),
                ),
            )
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('passport_entries.passport_name', 'like', Like::contains($search))
                ->orWhere('passport_entries.passport_number', 'like', Like::contains((string) Normalize::passportNumber($search)))
                ->orWhereHas('reference', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                ->orWhereHas('company', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))))
            ->when($reference !== '', fn (Builder $query) => $query->whereHas('reference', fn (Builder $query) => $query->where('name', 'like', Like::contains($reference))))
            ->when($company !== '', fn (Builder $query) => $query->whereHas('company', fn (Builder $query) => $query->where('name', 'like', Like::contains($company))))
            ->when($request->has('stage'), fn (Builder $query) => $query->where('passport_entries.current_stage', $request->validated('stage')))
            ->when($request->has('stage_status'), fn (Builder $query) => $query->where('passport_entries.stage_status', $request->validated('stage_status')))
            ->when($request->has('reference_id'), fn (Builder $query) => $query->where('reference_id', $request->integer('reference_id')))
            ->when($request->has('reference_type'), fn (Builder $query) => $query->whereHas('reference', fn (Builder $query) => $query->where('type', $request->validated('reference_type'))))
            ->when($request->has('company_id'), fn (Builder $query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->has('company_country_code'), fn (Builder $query) => $query->whereHas('company', fn (Builder $query) => $query->where('country_code', $request->validated('company_country_code'))))
            ->when($request->has('received_from'), fn (Builder $query) => $query->whereDate('passport_received_date', '>=', $request->validated('received_from')))
            ->when($request->has('received_to'), fn (Builder $query) => $query->whereDate('passport_received_date', '<=', $request->validated('received_to')));

        // The Medical page's Fit and Expiring soon tabs hold only passports not
        // yet marked Online; once marked they move to Calling pending.
        if ($mode === ListPassportEntriesRequest::MEDICAL && in_array($medicalStatus, ListPassportEntriesRequest::UNMARKED_TABS, true)) {
            $entries->whereNull('passport_entries.entry_mode');
        }

        $entries = $entries
            ->orderBy($sortColumn, $direction)
            ->orderBy('passport_entries.id', $direction);

        // All Passports: export=csv downloads every row with the same searches.
        if ($overview && $request->validated('export') === 'csv') {
            return $csv->download($entries);
        }

        $entries = $entries
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return $overview
            ? PassportOverviewResource::collection($entries)
            : PassportListResource::collection($entries);
    }

    /**
     * Record a received passport.
     */
    public function store(StorePassportEntryRequest $request, WorkflowService $workflow, PassportScanService $scans): PassportEntryResource
    {
        Gate::authorize('create', PassportEntry::class);

        // The entry and its scan copy are saved together.
        $entry = DB::transaction(function () use ($request, $workflow, $scans) {
            $entry = new PassportEntry($request->safe()->except('scan'));
            $entry->created_by = $request->user()->id;
            $entry->updated_by = $request->user()->id;
            $entry->save();
            $workflow->recompute($entry);

            return $scans->store($entry, $request->file('scan'));
        });

        return new PassportEntryResource($entry->load(PassportEntryResource::RELATIONS));
    }

    /**
     * A single passport entry.
     */
    public function show(PassportEntry $passport): PassportEntryResource
    {
        Gate::authorize('view', $passport);

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }

    /**
     * Edit a passport entry.
     */
    public function update(UpdatePassportEntryRequest $request, PassportEntry $passport, WorkflowService $workflow, PassportScanService $scans): PassportEntryResource
    {
        Gate::authorize('update', $passport);

        DB::transaction(function () use ($request, $passport, $workflow, $scans) {
            $passport->fill($request->safe()->except('scan'));
            $passport->updated_by = $request->user()->id;
            $passport->save();

            // A pending passport's status date is its received date.
            if ($passport->wasChanged('passport_received_date')) {
                $workflow->recompute($passport);
            }

            // Required while the passport has no scan yet; otherwise an optional replacement.
            if ($request->hasFile('scan')) {
                $scans->store($passport, $request->file('scan'));
            }
        });

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }

    /**
     * Soft delete a passport entry, recording who deleted it. Its passport
     * number stays taken.
     */
    public function destroy(Request $request, PassportEntry $passport): Response
    {
        Gate::authorize('delete', $passport);

        $passport->deleted_by = $request->user()->id;
        $passport->save();
        $passport->delete();

        return response()->noContent();
    }
}
