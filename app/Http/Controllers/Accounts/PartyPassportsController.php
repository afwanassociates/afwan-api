<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Support\PassportPricing;
use App\Support\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A party's passports by company, each with the receipts that paid toward it
 * and a status: paid (an approved receipt paid it off), pending (the receipt
 * paying it off waits for approval or was returned), part paid (only part
 * payments so far) or unpaid. The cash receive form ticks any passport not
 * paid off by another receipt; with receipt_id, the passports that receipt
 * pays are its own (ticked, and kept even if deleted since). country_code
 * keeps the passports going to that country (their company's country).
 * Each passport has its price, what other receipts paid toward it and its
 * due; one without a price cannot be ticked (the receipt validation refuses it).
 */
class PartyPassportsController extends Controller
{
    public function __invoke(Request $request, Party $party, Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $party);

        $filters = $request->validate([
            'country_code' => ['nullable', 'string', 'size:2'],
            'receipt_id' => ['nullable', 'integer', Rule::exists('cash_receipts', 'id')],
        ]);
        $receiptId = isset($filters['receipt_id']) ? (int) $filters['receipt_id'] : null;
        $country = isset($filters['country_code']) ? strtoupper($filters['country_code']) : null;

        $payments = DB::table('cash_receipt_passports')
            ->join('cash_receipts', 'cash_receipts.id', '=', 'cash_receipt_passports.cash_receipt_id')
            ->join('cash_receipt_lines', 'cash_receipt_lines.id', '=', 'cash_receipt_passports.cash_receipt_line_id')
            ->select('cash_receipt_passports.passport_entry_id', 'cash_receipts.id', 'cash_receipts.receipt_no', 'cash_receipts.date', 'cash_receipts.status', 'cash_receipt_lines.is_full_payment')
            ->orderBy('cash_receipts.date')
            ->orderBy('cash_receipts.id');

        $passports = PassportEntry::withTrashed()
            ->where('reference_id', $party->id)
            // The destination: the company's country (a passport's own country_code is the holder's nationality).
            ->when($country, fn (Builder $q, string $code) => $q->whereIn(
                'company_id',
                DB::table('companies')->where('country_code', $code)->select('id'),
            ))
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')->when($receiptId, fn (Builder $q, int $id) => $q->orWhereIn(
                'id',
                DB::table('cash_receipt_passports')->where('cash_receipt_id', $id)->select('passport_entry_id'),
            )))
            ->with(['company' => fn ($q) => $q->withTrashed()->select('id', 'name', 'country_code')])
            ->orderBy('entry_no')
            ->get(['id', 'entry_no', 'passport_name', 'passport_number', 'country_code', 'company_id', 'current_stage', 'current_return_id', 'deleted_at', 'price', 'price_source']);

        $paid = $payments->whereIn('cash_receipt_passports.passport_entry_id', $passports->pluck('id'))->get()->groupBy('passport_entry_id');
        // What other receipts paid toward each passport (its share of their lines).
        $shares = PassportPricing::shares($receiptId)
            ->whereIn('crp.passport_entry_id', $passports->pluck('id'))
            ->pluck('paid', 'passport_entry_id');

        $groups = $passports->groupBy('company_id')->map(function (Collection $rows) use ($paid, $shares, $receiptId, $workflow) {
            $list = $rows->map(function (PassportEntry $passport) use ($paid, $shares, $receiptId, $workflow) {
                /** @var Collection<int, object> $receipts */
                $receipts = $paid->get($passport->id, collect());
                $full = $receipts->first(fn (object $r) => (bool) $r->is_full_payment);
                $status = match (true) {
                    $full !== null && $full->status === ApprovalStatus::Approved->value => 'paid',
                    $full !== null => 'pending',
                    $receipts->isNotEmpty() => 'part_paid',
                    default => 'unpaid',
                };
                $onThisReceipt = $receiptId !== null && $receipts->contains(fn (object $r) => (int) $r->id === $receiptId);
                $paidOffElsewhere = $receipts->contains(fn (object $r) => (bool) $r->is_full_payment && (int) $r->id !== $receiptId);
                $price = $passport->price !== null ? (float) $passport->price : null;
                $paidSoFar = round((float) ($shares[$passport->id] ?? 0), 2);

                return [
                    'id' => $passport->id,
                    'entry_no' => $passport->entry_no,
                    'passport_name' => $passport->passport_name,
                    'passport_number' => $passport->passport_number,
                    'country_code' => $passport->country_code,
                    'stage' => $passport->current_return_id !== null ? 'Returned'
                        : ($passport->current_stage ? $workflow->label($passport->current_stage) : null),
                    'is_deleted' => $passport->trashed(),
                    'status' => $status,
                    // Every receipt that paid toward it, oldest first.
                    'receipts' => $receipts->map(fn (object $r) => [
                        'id' => (int) $r->id,
                        'receipt_no' => $r->receipt_no,
                        'is_full_payment' => (bool) $r->is_full_payment,
                        'is_approved' => $r->status === ApprovalStatus::Approved->value,
                    ])->values(),
                    // On the receipt being edited: ticked, and the form may untick it.
                    'on_this_receipt' => $onThisReceipt,
                    // The locked price (the party's rate, else the company's standard rate, or special).
                    'price' => $price,
                    'price_source' => $passport->price_source,
                    // Paid toward it on other receipts (approved or waiting), and what is left.
                    'paid' => $paidSoFar,
                    'due' => $price === null ? null : round($price - $paidSoFar, 2),
                    // Not paid off by another receipt, not deleted and with a price (unless already on this one).
                    'can_tick' => ! $paidOffElsewhere && ($onThisReceipt || (! $passport->trashed() && $price !== null)),
                ];
            })->values();

            return [
                'company' => [
                    'id' => $rows->first()->company_id,
                    'name' => $rows->first()->company?->name,
                    'country_code' => $rows->first()->company?->country_code,
                ],
                'counts' => self::counts($list),
                'passports' => $list,
            ];
        })->sortBy(fn (array $group) => mb_strtolower((string) $group['company']['name']))->values();

        return response()->json([
            'data' => $groups,
            'meta' => ['counts' => self::counts($groups->pluck('passports')->flatten(1))],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $passports
     * @return array{total: int, paid: int, pending: int, part_paid: int, unpaid: int}
     */
    private static function counts(Collection $passports): array
    {
        return [
            'total' => $passports->count(),
            'paid' => $passports->where('status', 'paid')->count(),
            'pending' => $passports->where('status', 'pending')->count(),
            'part_paid' => $passports->where('status', 'part_paid')->count(),
            'unpaid' => $passports->where('status', 'unpaid')->count(),
        ];
    }
}
