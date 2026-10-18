<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

describe('dashboard summary', function () {
    it('counts every passport across companies, soft-deleted ones left out', function () {
        $attributes = ['passport_received_date' => '2026-01-15', 'passport_expiry_date' => '2035-01-01'];
        PassportEntry::factory()->create([...$attributes, 'medical_slip_date' => null]);
        PassportEntry::factory()->count(2)->create([...$attributes, 'medical_slip_date' => '2026-01-15']);
        $unfit = PassportEntry::factory()->create([...$attributes, 'medical_slip_date' => '2026-01-15']);
        MedicalRecord::factory()->for($unfit)->on('2026-04-01')->unfit()->create();
        PassportEntry::factory()->create($attributes)->delete();

        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.not_started', 1)
            ->assertJsonPath('data.medical_pending', 2)
            ->assertJsonPath('data.medical_unfit', 1)
            ->assertJsonPath('data.medical_fit', 0)
            ->assertJsonPath('data.flight_done', 0)
            ->assertJsonPath('data.in_process', 3);
    });

    it('is open to every staff role', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('data.total', 0);
    })->with(['superAdmin', 'admin', 'dataEntry', 'accounts']);

    it('rejects guests', function () {
        $this->getJson('/api/reports/summary')->assertUnauthorized();
    });
});
