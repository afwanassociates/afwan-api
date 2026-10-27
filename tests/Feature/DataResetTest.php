<?php

use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\Reference;
use App\Models\User;
use App\Services\DataReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Some data in every group, and one scan file. */
function seedEveryGroup(): void
{
    $passport = PassportEntry::factory()->create();
    MedicalRecord::factory()->create(['passport_entry_id' => $passport->id]);
    PassportStepRecord::factory()->create(['passport_entry_id' => $passport->id]);
    $party = Party::factory()->create();
    CashReceipt::factory()->create(['party_id' => $party->id]);
    DB::table('party_transactions')->insert([
        'party_id' => $party->id, 'date' => '2026-10-01', 'type' => 'receivable', 'amount' => 100,
        'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cash_settings')->where('id', 1)->update(['opening_balance' => 5000, 'opening_date' => '2026-10-01']);
    Storage::disk('local')->put("passport-scans/{$passport->id}/scan.jpg", 'scan-bytes');
}

function resetPayload(array $overrides = []): array
{
    return [
        'groups' => ['passports', 'companies', 'accounts'],
        'confirmation' => 'DELETE ALL DATA',
        'password' => 'password',
        ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-10-25 14:30:05');
});

afterEach(fn () => Carbon::setTestNow());

describe('delete all data', function () {
    it('is for the super admin only', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/super-admin/data-reset')->assertForbidden();
        $this->postJson('/api/super-admin/data-reset', resetPayload())->assertForbidden();
        $this->getJson('/api/super-admin/data-reset/backups/data-backup-2026-10-25-143005.tar.gz')->assertForbidden();
        $this->deleteJson('/api/super-admin/data-reset/backups/data-backup-2026-10-25-143005.tar.gz')->assertForbidden();
    })->with(['admin', 'accountsHead', 'accounts', 'dataEntry']);

    it('shows each group with its row and file counts', function () {
        seedEveryGroup();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/super-admin/data-reset')
            ->assertOk()
            ->assertJsonPath('data.confirmation', 'DELETE ALL DATA')
            ->assertJsonPath('data.groups.*.key', ['passports', 'companies', 'accounts'])
            ->assertJsonPath('data.groups.0.rows', 3)
            ->assertJsonPath('data.groups.0.files', 1)
            ->assertJsonPath('data.groups.2.rows', 3)
            ->assertJsonPath('data.backups', []);
    });

    it('deletes nothing without the exact words and the right password', function () {
        seedEveryGroup();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/data-reset', resetPayload(['confirmation' => 'delete all data']))
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson('/api/super-admin/data-reset', resetPayload(['password' => 'wrong']))
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The password is not correct.']);
        $this->postJson('/api/super-admin/data-reset', resetPayload(['groups' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('groups');
        $this->postJson('/api/super-admin/data-reset', resetPayload(['groups' => ['companies']]))
            ->assertUnprocessable()->assertJsonValidationErrors('groups');

        expect(PassportEntry::withTrashed()->count())->toBe(1)
            ->and(Party::count())->toBe(1)
            ->and(Storage::disk('local')->allFiles('data-backups'))->toBe([]);
    });

    it('backs up, then deletes every group and keeps users and settings', function () {
        seedEveryGroup();
        $admin = User::factory()->superAdmin()->create(['name' => 'Chief']);
        $usersBefore = User::count();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/data-reset', resetPayload())
            ->assertOk()
            ->assertJsonPath('data.backup', 'data-backup-2026-10-25-143005.tar.gz')
            ->assertJsonPath('data.files', 1)
            ->assertJsonPath('data.deleted.passport_entries', 1)
            ->assertJsonPath('data.deleted.parties', 1)
            ->assertJsonPath('data.groups.0.rows', 0)
            ->assertJsonPath('data.backups.0.created_by', 'Chief')
            ->assertJsonPath('data.backups.0.groups', ['passports', 'companies', 'accounts'])
            ->assertJsonPath('data.backups.0.files', 1);

        foreach (array_merge(...array_column(DataReset::GROUPS, 'tables')) as $table) {
            expect(DB::table($table)->count())->toBe(0, "{$table} should be empty");
        }
        expect(User::count())->toBe($usersBefore)
            ->and(DB::table('countries')->count())->toBeGreaterThan(0)
            ->and(Storage::disk('local')->allFiles('passport-scans'))->toBe([])
            ->and(DB::table('cash_settings')->where('id', 1)->value('opening_date'))->toBeNull();
    });

    it('can delete accounts only and keep passports', function () {
        seedEveryGroup();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/data-reset', resetPayload(['groups' => ['accounts']]))
            ->assertOk()
            ->assertJsonPath('data.files', 0);

        expect(Party::count())->toBe(0)
            ->and(CashReceipt::count())->toBe(0)
            ->and(PassportEntry::count())->toBe(1)
            ->and(Company::count())->toBeGreaterThan(0)
            ->and(Reference::count())->toBeGreaterThan(0)
            ->and(Storage::disk('local')->allFiles('passport-scans'))->toHaveCount(1);
    });

    it('numbers the passports of a backup made before the entry number existed', function () {
        expect(DataReset::numberPassports([['id' => 9, 'name' => 'B'], ['id' => 4, 'name' => 'A']]))
            ->toBe([['id' => 4, 'name' => 'A', 'entry_no' => 1], ['id' => 9, 'name' => 'B', 'entry_no' => 2]])
            ->and(DataReset::numberPassports([['id' => 1, 'entry_no' => 5], ['id' => 2, 'entry_no' => null]]))
            ->toBe([['id' => 1, 'entry_no' => 5], ['id' => 2, 'entry_no' => 6]]);
    });

    it('downloads, deletes and restores a backup', function () {
        seedEveryGroup();
        $before = [
            'passport_entries' => DB::table('passport_entries')->orderBy('id')->get()->toArray(),
            'parties' => DB::table('parties')->orderBy('id')->get()->toArray(),
        ];
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $name = $this->postJson('/api/super-admin/data-reset', resetPayload())->json('data.backup');

        $this->get("/api/super-admin/data-reset/backups/{$name}")
            ->assertOk()
            ->assertDownload($name);
        $this->getJson('/api/super-admin/data-reset/backups/..%2F.env')->assertNotFound();

        $this->artisan('app:restore-data-backup', ['file' => $name, '--force' => true])
            ->expectsOutputToContain('Restored 1 scan file(s).')
            ->assertSuccessful();

        expect(DB::table('passport_entries')->orderBy('id')->get()->toArray())->toEqual($before['passport_entries'])
            ->and(DB::table('parties')->orderBy('id')->get()->toArray())->toEqual($before['parties'])
            ->and(DB::table('cash_settings')->where('id', 1)->value('opening_balance'))->toBe('5000.00')
            ->and(Storage::disk('local')->allFiles('passport-scans'))->toHaveCount(1);

        // Restoring again is refused: the tables are no longer empty.
        $this->artisan('app:restore-data-backup', ['file' => $name, '--force' => true])->assertFailed();

        $this->deleteJson("/api/super-admin/data-reset/backups/{$name}")->assertNoContent();
        $this->getJson('/api/super-admin/data-reset')->assertJsonPath('data.backups', []);
    });
});
