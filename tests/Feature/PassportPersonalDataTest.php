<?php

use App\Models\PassportEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * The personal data page (father's name, mother's name, permanent address): optional,
 * uppercased and squished like the passport name, returned in the details.
 */

it('saves the personal data page normalized and returns it', function () {
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $id = $this->post('/api/data-entry/passports', passportForm([
        'father_name' => ' md.  yousuf ali ',
        'mother_name' => 'mst. shamsunnahar',
        'permanent_address' => 'paschim chatol,  katiadi, chatol - 2331, kishoreganj',
    ]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.father_name', 'MD. YOUSUF ALI')
        ->assertJsonPath('data.mother_name', 'MST. SHAMSUNNAHAR')
        ->assertJsonPath('data.permanent_address', 'PASCHIM CHATOL, KATIADI, CHATOL - 2331, KISHOREGANJ')
        ->json('data.id');

    $this->getJson("/api/data-entry/passports/{$id}")
        ->assertJsonPath('data.father_name', 'MD. YOUSUF ALI');
});

it('keeps the personal data page optional and stores empty as null', function () {
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->post('/api/data-entry/passports', passportForm(['father_name' => '', 'mother_name' => '   ']), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.father_name', null)
        ->assertJsonPath('data.mother_name', null)
        ->assertJsonPath('data.permanent_address', null);

    expect(PassportEntry::first()->father_name)->toBeNull();
});

it('limits the lengths', function (string $field, int $max) {
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->post('/api/data-entry/passports', passportForm([$field => str_repeat('A', $max + 1)]), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([['father_name', 150], ['mother_name', 150], ['permanent_address', 255]]);
