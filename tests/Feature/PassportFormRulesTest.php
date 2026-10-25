<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * A complete passport form.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function passportForm(array $overrides = []): array
{
    return [
        'passport_name' => 'Md Karim Ali',
        'passport_number' => 'AB1234567',
        'country_code' => 'BD',
        'date_of_birth' => '1995-04-12',
        'reference_id' => Reference::factory()->create()->id,
        'company_id' => Company::factory()->create()->id,
        'passport_received_date' => now()->subDay()->toDateString(),
        'passport_expiry_date' => now()->addYears(5)->toDateString(),
        'scan' => fakeScan(),
        ...$overrides,
    ];
}

describe('required fields', function () {
    it('requires each field on create, missing or empty', function (string $field, mixed $value) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $form = passportForm();
        $value === '__missing' ? data_forget($form, $field) : $form[$field] = $value;

        $this->postJson('/api/data-entry/passports', $form)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(PassportEntry::count())->toBe(0);
    })->with(function () {
        $fields = ['passport_name', 'passport_number', 'passport_expiry_date', 'date_of_birth', 'passport_received_date', 'country_code', 'company_id'];

        return collect($fields)->flatMap(fn (string $field) => [
            "{$field} missing" => [$field, '__missing'],
            "{$field} empty" => [$field, ''],
        ])->all();
    });

    it('keeps the date format rules', function (string $field) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportForm([$field => '12/04/1995']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with(['date_of_birth', 'passport_received_date', 'passport_expiry_date']);

    it('requires a reference on create', function (string $case) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $form = passportForm();
        $case === 'missing' ? data_forget($form, 'reference_id') : $form['reference_id'] = $case === 'null' ? null : '';

        $this->postJson('/api/data-entry/passports', $form)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_id' => 'Please choose a reference.']);

        expect(PassportEntry::count())->toBe(0);
    })->with(['missing', 'null', 'empty']);

    it('rejects a reference that does not exist or cannot be chosen', function (string $case) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $reference = match ($case) {
            'unknown' => 999999,
            'not a number' => 'abc',
            'inactive' => Reference::factory()->create(['is_active' => false])->id,
            'deleted' => tap(Reference::factory()->create())->delete()->id,
        };

        $this->postJson('/api/data-entry/passports', passportForm(['reference_id' => $reference]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_id');

        expect(PassportEntry::count())->toBe(0);
    })->with(['unknown', 'not a number', 'inactive', 'deleted']);

    it('creates the passport with a valid reference', function () {
        $reference = Reference::factory()->create(['name' => 'Abdul Karim']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $id = $this->postJson('/api/data-entry/passports', passportForm(['reference_id' => $reference->id]))
            ->assertCreated()
            ->assertJsonPath('data.reference.id', $reference->id)
            ->assertJsonPath('data.reference.name', 'Abdul Karim')
            ->json('data.id');

        expect(PassportEntry::find($id)->reference_id)->toBe($reference->id);
    });

    it('requires a field on edit only when it is sent', function () {
        Sanctum::actingAs($user = User::factory()->dataEntry()->create());
        $entry = PassportEntry::factory()->create(['created_by' => $user->id]); // data entry edits only their own

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'New Name'])->assertOk();

        foreach (['passport_expiry_date', 'date_of_birth', 'country_code', 'company_id'] as $field) {
            $this->patchJson("/api/data-entry/passports/{$entry->id}", [$field => ''])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        // Saving the form with the reference emptied is refused.
        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['reference_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_id' => 'Please choose a reference.']);
    });
});

describe('old records', function () {
    it('still load, list and show without a reference, and are asked for one on the next full save', function () {
        $entry = PassportEntry::factory()->create(['passport_name' => 'No Reference']);
        // As saved while the reference was optional.
        DB::table('passport_entries')->where('id', $entry->id)->update(['reference_id' => null]);
        Sanctum::actingAs($user = User::factory()->dataEntry()->create());
        $entry->forceFill(['created_by' => $user->id])->save(); // data entry edits only their own

        $this->getJson("/api/data-entry/passports/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', null)
            ->assertJsonPath('data.passport_name', 'NO REFERENCE');
        $this->getJson('/api/data-entry/passports')->assertOk()->assertJsonPath('data.0.reference', null);
        $this->getJson('/api/data-entry/passports?view=overview&medical_status=all')->assertOk()->assertJsonPath('data.0.reference', null);
        $this->getJson("/api/reports/companies/{$entry->company_id}/passports")->assertOk()->assertJsonPath('data.0.reference', null);

        // Partial saves keep working without it.
        $this->patchJson("/api/data-entry/passports/{$entry->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertOk();
        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'Renamed'])->assertOk();

        // The full edit form sends every field: now it needs a reference.
        $form = passportForm(['passport_number' => $entry->passport_number]);
        $this->patchJson("/api/data-entry/passports/{$entry->id}", [...$form, 'reference_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_id' => 'Please choose a reference.']);
        $this->patchJson("/api/data-entry/passports/{$entry->id}", $form)
            ->assertOk()
            ->assertJsonPath('data.reference.id', $form['reference_id']);
    });

    it('still load and stay editable with an empty expiry, date of birth or country', function () {
        $entry = PassportEntry::factory()->create(['passport_name' => 'Old Entry']);
        // As saved before these fields existed.
        DB::table('passport_entries')->where('id', $entry->id)->update(['passport_expiry_date' => null, 'date_of_birth' => null, 'country_code' => null]);
        Sanctum::actingAs($user = User::factory()->dataEntry()->create());
        $entry->forceFill(['created_by' => $user->id])->save(); // data entry edits only their own

        $this->getJson("/api/data-entry/passports/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.passport_expiry_date', null)
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.country', null)
            ->assertJsonPath('data.workflow.steps.0.state', 'needs_attention');
        $this->getJson('/api/data-entry/passports')->assertOk()->assertJsonPath('data.0.id', $entry->id);
        $this->getJson('/api/data-entry/passports?view=overview')->assertOk()->assertJsonPath('data.0.passport_expiry_date', null);

        // Partial endpoints keep working without the missing fields.
        $this->patchJson("/api/data-entry/passports/{$entry->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertOk();

        Sanctum::actingAs($user);
        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'Old Entry Renamed'])
            ->assertOk()
            ->assertJsonPath('data.passport_expiry_date', null);
    });
});

describe('form defaults', function () {
    it('returns both default countries with their names to every role that creates passports', function (string $role) {
        app(Settings::class)->set(Settings::DEFAULT_PASSPORT_COUNTRY, 'BD');
        app(Settings::class)->set(Settings::DEFAULT_COMPANY_COUNTRY, 'SA');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/data-entry/defaults')
            ->assertOk()
            ->assertJsonPath('data.default_passport_country', ['code' => 'BD', 'name' => 'Bangladesh'])
            ->assertJsonPath('data.default_company_country', ['code' => 'SA', 'name' => 'Saudi Arabia'])
            ->assertJsonPath('data.default_passport_country_code', 'BD')
            ->assertJsonPath('data.default_company_country_code', 'SA');
    })->with(['dataEntry', 'admin', 'superAdmin']);

    it('falls back to Malaysia only when the setting row is missing', function () {
        AppSetting::query()->delete();
        app(Settings::class)->set(Settings::DEFAULT_COMPANY_COUNTRY, null);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/defaults')
            ->assertOk()
            ->assertJsonPath('data.default_passport_country', ['code' => 'MY', 'name' => 'Malaysia'])
            // Saved empty on purpose: no fallback.
            ->assertJsonPath('data.default_company_country', null);
    });

    it('reflects a changed default', function () {
        // Admins change the settings and still read the defaults (the company form needs them).
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/settings', ['default_passport_country_code' => 'NP', 'default_company_country_code' => 'AE'])->assertOk();

        $this->getJson('/api/data-entry/defaults')
            ->assertJsonPath('data.default_passport_country', ['code' => 'NP', 'name' => 'Nepal'])
            ->assertJsonPath('data.default_company_country', ['code' => 'AE', 'name' => 'United Arab Emirates']);
    });

    it('is not open to accounts or guests', function () {
        $this->getJson('/api/data-entry/defaults')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/defaults')->assertForbidden();
    });
});
