<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates a company from Quick Add with its name, quota and country', function () {
    $clerk = User::factory()->dataEntry()->create();
    Sanctum::actingAs($clerk);

    $this->postJson('/api/data-entry/companies', ['name' => '  Desert   Rose Construction ', 'quota' => 12, 'country_code' => 'my'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Desert Rose Construction')
        ->assertJsonPath('data.quota', 12)
        ->assertJsonPath('data.country.code', 'MY')
        ->assertJsonPath('data.agent_name', null);

    expect(Company::sole())->created_by->toBe($clerk->id)->is_active->toBeTrue();
});

it('requires the name, a quota of at least 1 and an active country, with clear messages', function (array $payload, string $field, string $message) {
    Company::factory()->create(['name' => 'Gulf Star Catering']);
    Country::whereKey('FR')->update(['is_active' => false]);
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->postJson('/api/data-entry/companies', [...['name' => 'New Employer', 'quota' => 10, 'country_code' => 'MY'], ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(Company::count())->toBe(1);
})->with([
    'no name' => [['name' => '   '], 'name', 'Enter the company name.'],
    'duplicate name, other case and spacing' => [['name' => ' gulf  star CATERING '], 'name', 'A company with this name already exists.'],
    'no quota' => [['quota' => null], 'quota', 'Enter the quota (the number of workers the company needs).'],
    'quota 0' => [['quota' => 0], 'quota', 'The quota must be at least 1.'],
    'negative quota' => [['quota' => -3], 'quota', 'The quota must be at least 1.'],
    'quota not a whole number' => [['quota' => 2.5], 'quota', 'The quota must be a whole number.'],
    'no country' => [['country_code' => null], 'country_code', 'Choose the company country.'],
    'inactive country' => [['country_code' => 'FR'], 'country_code', 'The selected country does not exist or is inactive.'],
    'unknown country' => [['country_code' => 'QQ'], 'country_code', 'The selected country does not exist or is inactive.'],
]);

it('keeps the admin Companies CRUD and existing companies without a quota valid', function () {
    $existing = Company::factory()->create(['quota' => null]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/admin/companies', ['name' => 'Admin Made Co', 'country_code' => 'MY'])
        ->assertCreated()
        ->assertJsonPath('data.quota', null);
    $this->patchJson("/api/admin/companies/{$existing->id}", ['agent_name' => 'New Agent'])
        ->assertOk()
        ->assertJsonPath('data.quota', null);
    $this->patchJson("/api/data-entry/companies/{$existing->id}", ['name' => 'Renamed Existing'])
        ->assertOk()
        ->assertJsonPath('data.quota', null);
});

it('lets accounts neither quick add nor reach it without a login', function () {
    $this->postJson('/api/data-entry/companies', ['name' => 'X Co', 'quota' => 1, 'country_code' => 'MY'])->assertUnauthorized();

    Sanctum::actingAs(User::factory()->accounts()->create());
    $this->postJson('/api/data-entry/companies', ['name' => 'X Co', 'quota' => 1, 'country_code' => 'MY'])->assertForbidden();
});
