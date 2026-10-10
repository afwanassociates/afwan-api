<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A passport received on $received with a current medical in the given state
 * ("pending" for none).
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewPassport(string $state, string $received = '2026-01-15', array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->create([
        'passport_received_date' => $received,
        'passport_expiry_date' => '2035-01-01',
        ...$attributes,
    ]);

    $factory = MedicalRecord::factory()->for($passport);

    match ($state) {
        'pending' => null,
        // Medical 2026-03-30.
        'fit' => $factory->validUntil('2026-06-30')->create(),
        // Medical 2026-02-10.
        'expiring_soon' => $factory->validUntil('2026-05-10')->create(),
        // Medical 2026-01-30; valid until yesterday in Dhaka (still today in UTC).
        'expired' => $factory->validUntil('2026-04-30')->create(),
        'unfit' => $factory->unfit()->on('2026-04-20')->create(),
    };

    return $passport->fresh();
}

/**
 * The overview response for the given query string.
 */
function overview(string $query = ''): TestResponse
{
    return test()->getJson("/api/data-entry/passports?view=overview&per_page=100&{$query}")->assertOk();
}

describe('latest status', function () {
    it('shows one latest status per medical case', function (string $state, array $expected) {
        $passport = overviewPassport($state);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(overview('medical_status=all')->json('data.0.id'))->toBe($passport->id)
            ->and(overview('medical_status=all')->json('data.0.latest_status'))->toBe($expected);
    })->with([
        'medical pending' => ['pending', [
            'stage' => 'medical', 'stage_label' => 'Medical', 'status' => 'pending', 'status_label' => 'Pending',
            'text' => 'Medical: Pending', 'tone' => 'amber', 'date' => '2026-01-15',
        ]],
        'unfit' => ['unfit', [
            'stage' => 'medical', 'stage_label' => 'Medical', 'status' => 'unfit', 'status_label' => 'Unfit',
            'text' => 'Medical: Unfit', 'tone' => 'red', 'date' => '2026-04-20',
        ]],
        'fit, waiting for the next step' => ['fit', [
            'stage' => 'calling', 'stage_label' => 'Calling / Work Permit', 'status' => 'waiting', 'status_label' => 'Waiting',
            'text' => 'Calling / Work Permit: Waiting', 'tone' => 'amber', 'date' => '2026-03-30',
        ]],
        'fit and expiring soon, still waiting' => ['expiring_soon', [
            'stage' => 'calling', 'stage_label' => 'Calling / Work Permit', 'status' => 'waiting', 'status_label' => 'Waiting',
            'text' => 'Calling / Work Permit: Waiting', 'tone' => 'amber', 'date' => '2026-02-10',
        ]],
        'fit but expired (Dhaka date)' => ['expired', [
            'stage' => 'calling', 'stage_label' => 'Calling / Work Permit', 'status' => 'expired', 'status_label' => 'Expired',
            'text' => 'Medical: Expired', 'tone' => 'amber', 'date' => '2026-01-30',
        ]],
    ]);

    it('takes step labels and tones from config', function () {
        config([
            'workflow.steps.2.label' => 'Calling',
            'workflow.tones.waiting' => 'blue',
            'workflow.tones.unfit' => null,
        ]);
        overviewPassport('fit');
        overviewPassport('unfit');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $statuses = collect(overview('medical_status=all')->json('data.*.latest_status'))->keyBy('status');

        expect($statuses['waiting'])->toMatchArray(['text' => 'Calling: Waiting', 'stage_label' => 'Calling', 'tone' => 'blue'])
            ->and($statuses['unfit']['tone'])->toBe('gray');
    });

    it('turns a fit medical into "Medical: Expired" as time passes, keeping the stored date', function () {
        $passport = overviewPassport('fit');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(overview()->json('data.0.latest_status'))->toMatchArray(['text' => 'Calling / Work Permit: Waiting', 'date' => '2026-03-30']);

        // valid_until is 2026-06-30: 00:30 on 1 July in Dhaka is still 30 June in UTC.
        Carbon::setTestNow(Carbon::parse('2026-07-01 00:30', 'Asia/Dhaka')->utc());

        expect(overview()->json('data.0.latest_status'))->toMatchArray(['text' => 'Medical: Expired', 'status' => 'expired', 'date' => '2026-03-30'])
            ->and($passport->fresh()->stage_status_date->toDateString())->toBe('2026-03-30');
    });
});

describe('stage_status_date', function () {
    it('is set by recompute from the records, for each case', function () {
        $passport = overviewPassport('pending', '2026-02-01');
        expect($passport->stage_status_date->toDateString())->toBe('2026-02-01');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Unfit: the current medical's date.
        $unfit = $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-03-01', 'result' => 'unfit'])->json('data.id');
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-03-01');

        // Fit re-test: waiting for the next step since the medical date.
        $fit = $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-10', 'result' => 'fit'])->json('data.id');
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-04-10');

        // A back-dated medical does not move it.
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-02-15', 'result' => 'fit'])->assertCreated();
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-04-10');

        // Editing the current medical's date moves it.
        $this->patchJson("/api/data-entry/medicals/{$fit}", ['medical_date' => '2026-04-12'])->assertOk();
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-04-12');

        // Deleting falls back to the previous latest record, then to the received date.
        $this->deleteJson("/api/data-entry/medicals/{$fit}")->assertNoContent();
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-03-01');

        MedicalRecord::query()->whereKeyNot($unfit)->get()->each(fn (MedicalRecord $record) => $this->deleteJson("/api/data-entry/medicals/{$record->id}"));
        $this->deleteJson("/api/data-entry/medicals/{$unfit}")->assertNoContent();
        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-02-01');
    });

    it('is set when a passport is created and follows edits to its received date while pending', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $passport = overviewPassport('pending', '2026-02-01');

        $this->patchJson("/api/data-entry/passports/{$passport->id}", ['passport_received_date' => '2026-02-05'])->assertOk();

        expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-02-05');
    });

    it('does not change with the passing of time', function () {
        $passports = collect(['pending', 'fit', 'expired', 'unfit'])
            ->mapWithKeys(fn (string $state) => [$state => overviewPassport($state)]);
        $before = PassportEntry::query()->orderBy('id')->pluck('stage_status_date', 'id')->map->toDateString()->all();

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:30', 'Asia/Dhaka')->utc());
        $passports->each(fn (PassportEntry $passport) => app(WorkflowService::class)->recompute($passport->fresh()));

        expect(PassportEntry::query()->orderBy('id')->pluck('stage_status_date', 'id')->map->toDateString()->all())->toBe($before);
    });

    it('is backfilled for existing rows by recomputeAll', function () {
        $pending = overviewPassport('pending', '2026-02-01');
        $fit = overviewPassport('fit');
        $unfit = overviewPassport('unfit');
        $deleted = overviewPassport('fit');
        $deleted->delete();

        // Rows from before the column existed.
        DB::table('passport_entries')->update(['stage_status_date' => null]);

        expect(app(WorkflowService::class)->recomputeAll(chunk: 2))->toBe(4)
            ->and($pending->fresh()->stage_status_date->toDateString())->toBe('2026-02-01')
            ->and($fit->fresh()->stage_status_date->toDateString())->toBe('2026-03-30')
            ->and($unfit->fresh()->stage_status_date->toDateString())->toBe('2026-04-20')
            ->and(PassportEntry::withTrashed()->find($deleted->id)->stage_status_date->toDateString())->toBe('2026-03-30');
    });
});

describe('overview list', function () {
    it('returns only the lean fields', function () {
        overviewPassport('fit');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $item = overview()->json('data.0');

        expect(array_keys($item))->toBe([
            'id', 'passport_name', 'passport_number', 'date_of_birth', 'country', 'passport_expiry_date',
            'company', 'reference', 'passport_received_date', 'entered_by', 'created_at', 'latest_status',
        ])
            ->and($item)->not->toHaveKeys(['current_medical', 'workflow', 'warnings', 'medical_status', 'can'])
            ->and(array_keys($item['reference']))->toBe(['id', 'name', 'type'])
            ->and(array_keys($item['company']))->toBe(['id', 'name', 'country']);
    });

    it('names who entered the passport', function () {
        $clerk = User::factory()->dataEntry()->create(['name' => 'Rafi']);
        PassportEntry::factory()->createdBy($clerk)->create();
        Sanctum::actingAs($clerk);

        expect(overview()->json('data.0.entered_by'))->toBe(['id' => $clerk->id, 'name' => 'Rafi']);
    });

    it('leaves the full list unchanged without view', function () {
        overviewPassport('fit');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/passports')
            ->assertOk()
            ->assertJsonStructure(['data' => [['current_medical', 'workflow', 'medical_status', 'can']]])
            ->assertJsonMissingPath('data.0.latest_status');
    });

    it('rejects an unknown view and accepts an empty one', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/passports?view=bogus')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('view');
        $this->getJson('/api/data-entry/passports?view=')
            ->assertOk()
            ->assertJsonStructure(['data']);
    });

    it('works with medical_status, search and filters', function () {
        $unfit = overviewPassport('unfit', '2026-02-10', ['passport_number' => 'UF1234567']);
        $fit = overviewPassport('fit', '2026-02-20', ['company_id' => $unfit->company_id]);
        $pending = overviewPassport('pending', '2026-03-15');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $ids = fn (string $query) => overview($query)->json('data.*.id');

        expect($ids(''))->toEqualCanonicalizing([$fit->id, $pending->id])
            ->and($ids('medical_status=all'))->toEqualCanonicalizing([$unfit->id, $fit->id, $pending->id])
            ->and($ids('medical_status=unfit'))->toBe([$unfit->id])
            ->and($ids('medical_status=all&q=UF1234567'))->toBe([$unfit->id])
            ->and($ids("medical_status=all&company_id={$unfit->company_id}"))->toEqualCanonicalizing([$unfit->id, $fit->id])
            ->and($ids("medical_status=all&company_country_code={$unfit->company->country_code}"))->toContain($unfit->id)
            ->and($ids("medical_status=all&reference_id={$pending->reference_id}"))->toBe([$pending->id])
            ->and($ids('medical_status=all&received_from=2026-02-01&received_to=2026-02-28'))->toEqualCanonicalizing([$unfit->id, $fit->id]);
    });

    it('sorts by status date and paginates', function () {
        $unfit = overviewPassport('unfit');      // 2026-04-20
        $fit = overviewPassport('fit');          // 2026-03-30
        $pending = overviewPassport('pending', '2026-02-01');
        $expired = overviewPassport('expired');  // 2026-01-30
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(overview('medical_status=all&sort=status_date&direction=asc')->json('data.*.id'))
            ->toBe([$expired->id, $pending->id, $fit->id, $unfit->id])
            ->and(overview('medical_status=all&sort=-status_date')->json('data.*.id'))
            ->toBe([$unfit->id, $fit->id, $pending->id, $expired->id]);

        $this->getJson('/api/data-entry/passports?view=overview&medical_status=all&sort=-status_date&per_page=3&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.*.id', [$expired->id]);
    });

    it('runs a fixed number of queries however many passports are listed', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            overview('medical_status=all');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Each entered by a different user, so the creator lookup is exercised.
        $create = fn (string $state) => overviewPassport($state, attributes: ['created_by' => User::factory()->dataEntry()->create()->id]);

        foreach (['fit', 'unfit', 'pending'] as $state) {
            $create($state);
        }
        $few = $countQueries();

        foreach (['unfit', 'expiring_soon', 'expired', 'pending', 'fit', 'unfit', 'expired', 'pending'] as $state) {
            $create($state);
        }

        // count + page + country + reference + company + company country
        // + creator + current medical.
        expect($countQueries())->toBe($few)->toBe(8);
    });

    it('rejects the accounts role and unauthenticated requests', function () {
        $this->getJson('/api/data-entry/passports?view=overview')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/passports?view=overview')->assertForbidden();
    });

    it('is available to data entry, admin and super admin', function (string $role) {
        overviewPassport('unfit');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        expect(overview('medical_status=all')->json('data.0.latest_status.text'))->toBe('Medical: Unfit');
    })->with(['dataEntry', 'admin', 'superAdmin']);
});
