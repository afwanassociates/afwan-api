<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StorePartyTransactionRequest;
use App\Http\Resources\PartyTransactionResource;
use App\Models\Party;
use App\Models\PartyTransaction;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Receivable lines entered by hand on a party ledger, until the
 * Sales module writes them (refunds are cash payments). A new or edited line is pending until
 * approved (App\Services\ApprovalService).
 */
class PartyTransactionController extends Controller
{
    public function store(StorePartyTransactionRequest $request, Party $party): PartyTransactionResource
    {
        Gate::authorize('create', PartyTransaction::class);

        $transaction = new PartyTransaction($request->validated());
        $transaction->party_id = $party->id;
        $transaction->created_by = $request->user()->id;
        $transaction->updated_by = $request->user()->id;
        $transaction->status = ApprovalStatus::Pending;
        $transaction->save();

        return new PartyTransactionResource($transaction->load(['creator:id,name', 'updater:id,name']));
    }

    public function update(StorePartyTransactionRequest $request, PartyTransaction $transaction): PartyTransactionResource
    {
        Gate::authorize('update', $transaction);

        $transaction->fill($request->validated());
        $transaction->updated_by = $request->user()->id;
        $transaction->status = ApprovalStatus::Pending;
        $transaction->save();

        return new PartyTransactionResource($transaction->load(['creator:id,name', 'updater:id,name']));
    }

    public function destroy(PartyTransaction $transaction): Response
    {
        Gate::authorize('delete', $transaction);

        $transaction->delete();

        return response()->noContent();
    }
}
