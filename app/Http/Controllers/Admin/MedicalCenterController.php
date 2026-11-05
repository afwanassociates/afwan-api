<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMedicalCentersRequest;
use App\Http\Requests\Admin\StoreMedicalCenterRequest;
use App\Http\Requests\Admin\UpdateMedicalCenterRequest;
use App\Http\Resources\MedicalCenterResource;
use App\Models\MedicalCenter;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MedicalCenterController extends Controller
{
    /**
     * Every medical centre, active and inactive.
     */
    public function index(ListMedicalCentersRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', MedicalCenter::class);

        $search = trim((string) $request->validated('q'));

        $centers = MedicalCenter::query()
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return MedicalCenterResource::collection($centers);
    }

    /**
     * Add a medical centre. New centres are active.
     */
    public function store(StoreMedicalCenterRequest $request): MedicalCenterResource
    {
        Gate::authorize('create', MedicalCenter::class);

        $center = new MedicalCenter($request->validated());
        $center->is_active = true;
        $center->created_by = $request->user()->id;
        $center->save();

        return new MedicalCenterResource($center);
    }

    /**
     * Rename or (de)activate a medical centre.
     */
    public function update(UpdateMedicalCenterRequest $request, MedicalCenter $medicalCenter): MedicalCenterResource
    {
        Gate::authorize('update', $medicalCenter);

        $medicalCenter->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $medicalCenter->is_active = $request->boolean('is_active');
        }

        $medicalCenter->save();

        return new MedicalCenterResource($medicalCenter);
    }

    /**
     * Soft delete a medical centre. Medicals recorded there keep showing it;
     * its name stays taken.
     */
    public function destroy(MedicalCenter $medicalCenter): Response
    {
        Gate::authorize('delete', $medicalCenter);

        $medicalCenter->delete();

        return response()->noContent();
    }
}
