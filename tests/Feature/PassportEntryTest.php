<?php

use App\Enums\ReferenceType;
use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * A valid request body; fresh active reference and company unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function passportPayload(array $overrides = []): array
{
    return [
        'passport_name' => 'Md Karim Ali',
        'passport_number' => 'AB1234567',
        'country_code' => 'BD',
        'date_of_birth' => '1995-04-12',
        'reference_id' => $overrides['reference_id'] ?? Reference::factory()->create()->id,
        'company_id' => $overrides['company_id'] ?? Company::factory()->create()->id,
        'passport_received_date' => now()->subDay()->toDateString(),
        'passport_expiry_date' => now()->addYears(5)->toDateString(),
        ...$overrides,
    ];
}

describe('access', function () {
    it('rejects unauthenticated requests', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/data-entry/passports'],
        ['POST', '/api/data-entry/passports'],
        ['GET', '/api/data-entry/passports/1'],
        ['PATCH', '/api/data-entry/passports/1'],
        ['DELETE', '/api/data-entry/passports/1'],
    ]);

    it('rejects the accounts role', function () {
        $entry = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/data-entry/passports')->assertForbidden();
        $this->postJson('/api/data-entry/passports', passportPayload())->assertForbidden();
        $this->getJson("/api/data-entry/passports/{$entry->id}")->assertForbidden();
        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/data-entry/passports/{$entry->id}")->assertForbidden();
    });
});

describe('create', function () {
    it('lets data entry create an entry, with created_by taken from the logged-in user', function () {
        $clerk = User::factory()->dataEntry()->create();
        $someoneElse = User::factory()->admin()->create();
        Sanctum::actingAs($clerk);

        $response = $this->postJson('/api/data-entry/passports', passportPayload([
            'created_by' => $someoneElse->id,
            'updated_by' => $someoneElse->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.passport_number', 'AB1234567')
            ->assertJsonPath('data.created_by', $clerk->id)
            ->assertJsonPath('data.updated_by', $clerk->id);

        expect(PassportEntry::sole())
            ->created_by->toBe($clerk->id)
            ->updated_by->toBe($clerk->id);
    });

    it('normalizes the passport number and name', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload([
            'passport_number' => ' ab 12 345 67 ',
            'passport_name' => '  md   karim  ali ',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.passport_number', 'AB1234567')
            ->assertJsonPath('data.passport_name', 'MD KARIM ALI');
    });

    it('rejects a duplicate passport number, whatever its spacing or case', function () {
        PassportEntry::factory()->create(['passport_number' => 'AB1234567']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload(['passport_number' => 'ab 1234 567']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passport_number' => 'This passport number is already entered.']);
    });

    it('rejects the passport number of a soft-deleted entry', function () {
        PassportEntry::factory()->create(['passport_number' => 'AB1234567'])->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passport_number' => 'This passport number is already entered.']);
    });

    it('rejects an invalid passport number format', function (string $number) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload(['passport_number' => $number]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passport_number');
    })->with([
        'too short' => 'AB123',
        'too long' => str_repeat('A1', 11),
        'symbols' => 'AB-123456',
        'empty' => '   ',
    ]);

    it('rejects a received date in the future', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload([
            'passport_received_date' => now()->addDay()->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passport_received_date');
    });

    it('rejects an inactive, deleted or missing reference', function (Closure $reference) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload(['reference_id' => $reference()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_id');
    })->with([
        'inactive' => fn () => fn () => Reference::factory()->inactive()->create()->id,
        'deleted' => fn () => fn () => tap(Reference::factory()->create())->delete()->id,
        'missing' => fn () => fn () => 999999,
    ]);

    it('rejects an inactive, deleted or missing company', function (Closure $company) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload(['company_id' => $company()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    })->with([
        'inactive' => fn () => fn () => Company::factory()->inactive()->create()->id,
        'deleted' => fn () => fn () => tap(Company::factory()->create())->delete()->id,
        'missing' => fn () => fn () => 999999,
    ]);
});

describe('edit and delete', function () {
    it('lets data entry edit their own entry and keep its passport number', function () {
        $clerk = User::factory()->dataEntry()->create();
        $entry = PassportEntry::factory()->createdBy($clerk)->create(['passport_number' => 'AB1234567']);
        Sanctum::actingAs($clerk);

        $this->patchJson("/api/data-entry/passports/{$entry->id}", [
            'passport_name' => 'New Name',
            'passport_number' => 'ab1234567',
        ])
            ->assertOk()
            ->assertJsonPath('data.passport_name', 'NEW NAME')
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', false);
    });

    it('forbids data entry from editing someone else\'s entry', function () {
        $entry = PassportEntry::factory()->createdBy(User::factory()->dataEntry()->create())->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'X'])->assertForbidden();
    });

    it('lets admin and super admin edit any entry', function (string $role) {
        $clerk = User::factory()->dataEntry()->create();
        $entry = PassportEntry::factory()->createdBy($clerk)->create();
        $editor = User::factory()->{$role}()->create();
        Sanctum::actingAs($editor);

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'Edited'])
            ->assertOk()
            ->assertJsonPath('data.passport_name', 'EDITED')
            ->assertJsonPath('data.created_by', $clerk->id)
            ->assertJsonPath('data.updated_by', $editor->id);
    })->with(['admin', 'superAdmin']);

    it('rejects changing to another entry\'s passport number', function () {
        PassportEntry::factory()->create(['passport_number' => 'TAKEN1234']);
        $entry = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_number' => 'taken 1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passport_number' => 'This passport number is already entered.']);
    });

    it('forbids data entry from deleting, even their own entry', function () {
        $clerk = User::factory()->dataEntry()->create();
        $entry = PassportEntry::factory()->createdBy($clerk)->create();
        Sanctum::actingAs($clerk);

        $this->deleteJson("/api/data-entry/passports/{$entry->id}")->assertForbidden();
        $this->assertNotSoftDeleted($entry);
    });

    it('lets admin and super admin soft delete', function (string $role) {
        $entry = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->deleteJson("/api/data-entry/passports/{$entry->id}")->assertNoContent();
        $this->assertSoftDeleted($entry);
        $this->getJson("/api/data-entry/passports/{$entry->id}")->assertNotFound();
    })->with(['admin', 'superAdmin']);
});

describe('list', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->person = Reference::factory()->person()->create(['name' => 'Abdul Karim']);
        $this->agency = Reference::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        $this->gulf = Company::factory()->create(['name' => 'Gulf Star Catering']);
        $this->pearl = Company::factory()->create(['name' => 'Pearl Coast Facilities']);

        PassportEntry::factory()->create(['passport_name' => 'Rahim Uddin', 'passport_number' => 'AA1111111', 'reference_id' => $this->person, 'company_id' => $this->gulf, 'passport_received_date' => '2026-01-10']);
        PassportEntry::factory()->create(['passport_name' => 'Salma Khatun', 'passport_number' => 'BB2222222', 'reference_id' => $this->person, 'company_id' => $this->pearl, 'passport_received_date' => '2026-02-10']);
        PassportEntry::factory()->create(['passport_name' => 'Jamal Hossain', 'passport_number' => 'CC3333333', 'reference_id' => $this->agency, 'company_id' => $this->pearl, 'passport_received_date' => '2026-03-10']);
    });

    it('searches by passport name, number, reference name and company name', function (string $q, array $expected) {
        $numbers = collect($this->getJson('/api/data-entry/passports?'.http_build_query(['q' => $q]))->assertOk()->json('data'))
            ->pluck('passport_number')->sort()->values()->all();

        expect($numbers)->toBe($expected);
    })->with([
        'passport name' => ['salma', ['BB2222222']],
        'passport number with spaces, lowercase' => ['cc 333', ['CC3333333']],
        'reference name' => ['karim', ['AA1111111', 'BB2222222']],
        'company name' => ['pearl', ['BB2222222', 'CC3333333']],
        'LIKE wildcards match literally' => ['%', []],
    ]);

    it('filters', function (array $query, array $expected) {
        // reference_id and company_id name a fixture from beforeEach.
        $query = collect($query)->map(fn ($value, $key) => in_array($key, ['reference_id', 'company_id']) ? $this->{$value}->id : $value)->all();

        $numbers = collect($this->getJson('/api/data-entry/passports?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('passport_number')->sort()->values()->all();

        expect($numbers)->toBe($expected);
    })->with([
        'reference_id' => [['reference_id' => 'agency'], ['CC3333333']],
        'reference_type' => [['reference_type' => ReferenceType::Person->value], ['AA1111111', 'BB2222222']],
        'company_id' => [['company_id' => 'gulf'], ['AA1111111']],
        'received_from' => [['received_from' => '2026-02-10'], ['BB2222222', 'CC3333333']],
        'received_to' => [['received_to' => '2026-02-10'], ['AA1111111', 'BB2222222']],
        'received_from and received_to' => [['received_from' => '2026-02-01', 'received_to' => '2026-02-28'], ['BB2222222']],
    ]);

    it('sorts by received date and paginates', function () {
        $this->getJson('/api/data-entry/passports?sort=passport_received_date&direction=asc&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.passport_number', 'CC3333333')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('lists the newest entries first by default, 15 per page', function () {
        PassportEntry::factory()->count(15)->create();

        $this->getJson('/api/data-entry/passports')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('data.0.id', PassportEntry::max('id'));
    });

    it('rejects invalid list parameters', function (array $query, string $field) {
        $this->getJson('/api/data-entry/passports?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'per_page over 100' => [['per_page' => 101], 'per_page'],
        'unknown sort column' => [['sort' => 'passport_number'], 'sort'],
        'unknown reference type' => [['reference_type' => 'friend'], 'reference_type'],
        'received_to before received_from' => [['received_from' => '2026-03-01', 'received_to' => '2026-01-01'], 'received_to'],
    ]);

    it('runs a fixed number of queries however many rows it returns', function (int $perPage) {
        PassportEntry::factory()->count(12)->create(['country_code' => 'MY']);

        DB::enableQueryLog();
        $this->getJson("/api/data-entry/passports?per_page={$perPage}&company_country_code=my")->assertOk()->assertJsonCount($perPage, 'data');

        // count + page + passport countries + references + companies + company countries
        expect(DB::getQueryLog())->toHaveCount(6);
    })->with([2, 15]);
});
