<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The operations head cancels a medical result (Undo of Fit): the passport goes back to
 * Medical → Pending, the record stays in the history as cancelled, until Calling starts.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
});

function cancelFitPassport(): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(['passport_received_date' => '2026-03-01']);
    Sanctum::actingAs(User::factory()->dataEntry()->create());
    recordMedical($passport->id, '2026-04-10', 'fit')->assertCreated();

    return $passport->fresh();
}

it('lets the operations head cancel a fit result: back to Pending, kept in the history', function () {
    $passport = cancelFitPassport();
    $passport->forceFill(['entry_mode' => 'online'])->saveQuietly();
    $record = MedicalRecord::query()->where('passport_entry_id', $passport->id)->sole();

    $head = User::factory()->operationHead()->create();
    Sanctum::actingAs($head);
    $this->getJson("/api/data-entry/passports/{$passport->id}")->assertJsonPath('data.can.cancel_medical_result', true);

    $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result/cancel")
        ->assertOk()
        ->assertJsonPath('passport.medical_status', 'pending')
        ->assertJsonPath('passport.current_medical_id', null);

    $passport->refresh();
    expect($passport->current_medical_id)->toBeNull()
        ->and($passport->entry_mode)->toBeNull()
        ->and(MedicalRecord::withTrashed()->find($record->id))
        ->deleted_at->not->toBeNull()
        ->deleted_by->toBe($head->id);

    $titles = collect($this->getJson("/api/data-entry/passports/{$passport->id}/history")->json('data'))->pluck('title');
    expect($titles)->toContain('Medical Fit (cancelled)');

    // A new result can be recorded again.
    Sanctum::actingAs(User::factory()->dataEntry()->create());
    $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'unfit'])->assertCreated();
});

it('is for the operations head only', function () {
    $passport = cancelFitPassport();

    $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result/cancel")->assertForbidden();
    $this->getJson("/api/data-entry/passports/{$passport->id}")->assertJsonPath('data.can.cancel_medical_result', false);
});

it('is refused once Calling has started', function () {
    $passport = cancelFitPassport();
    $passport->forceFill(['entry_mode' => 'online'])->saveQuietly();
    $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", [
        'receive_date' => '2026-04-20', 'issue_date' => '2026-04-18', 'valid_until' => '2026-10-18',
    ])->assertSuccessful();

    Sanctum::actingAs(User::factory()->operationHead()->create());
    $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result/cancel")->assertForbidden();
});
