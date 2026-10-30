<?php

namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\PassportEntry;
use App\Models\PassportReturn;
use App\Models\User;
use App\Support\Workflow;
use Illuminate\Support\Facades\DB;

/**
 * Returning a passport to its owner (at any step until the flight is
 * confirmed), and undoing that. The only place that
 * writes passport_returns and a passport's current_return_id.
 *
 * A return leaves the stored workflow position (current_stage, stage_status,
 * stage_status_date) as it is: the working lists and step counts leave out
 * passports with a current return, and WorkflowService / MedicalService refuse
 * every change while it is in force. Undoing it clears current_return_id, so
 * the passport is back exactly where it stood. A return is never deleted.
 */
class PassportReturnService
{
    /**
     * @param  array{return_date: string, receiver_name: string, receiver_phone: string, receiver_id_no?: string|null, remarks?: string|null}  $data
     *
     * @throws WorkflowException when the passport is already returned
     */
    public function returnPassport(PassportEntry $passport, array $data, User $actor): PassportReturn
    {
        return DB::transaction(function () use ($passport, $data, $actor) {
            $locked = $this->lock($passport);

            if ($locked->current_return_id !== null) {
                throw WorkflowException::alreadyReturned();
            }

            if ($locked->current_stage === Workflow::COMPLETED) {
                throw WorkflowException::flightConfirmed();
            }

            $return = new PassportReturn($data);
            $return->passport_entry_id = $locked->id;
            $return->stage = (string) ($locked->current_stage ?? 'passport');
            $return->stage_status = $locked->stage_status;
            $return->returned_by = $actor->id;
            $return->save();

            $this->setCurrent($passport, $return->id);

            return $return;
        });
    }

    /**
     * Undo the return in force: it stays in the history as undone.
     *
     * @throws WorkflowException when the passport is not returned
     */
    public function undo(PassportEntry $passport, User $actor): PassportReturn
    {
        return DB::transaction(function () use ($passport, $actor) {
            $locked = $this->lock($passport);
            $return = $locked->current_return_id === null ? null : PassportReturn::query()->find($locked->current_return_id);

            if ($return === null) {
                throw WorkflowException::notReturned();
            }

            $return->undone_at = now();
            $return->undone_by = $actor->id;
            $return->save();

            $this->setCurrent($passport, null);

            return $return;
        });
    }

    /**
     * Query builder update: returning is not an edit of the passport details,
     * so its updated_at and updated_by stay as they are.
     */
    protected function setCurrent(PassportEntry $passport, ?int $returnId): void
    {
        PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update(['current_return_id' => $returnId]);
        $passport->setAttribute('current_return_id', $returnId)->syncOriginalAttribute('current_return_id');
        $passport->unsetRelation('currentReturn');
    }

    /**
     * Lock the passport row so a return and a workflow change never overlap.
     */
    protected function lock(PassportEntry $passport): PassportEntry
    {
        return PassportEntry::withTrashed()->lockForUpdate()->findOrFail($passport->id);
    }
}
