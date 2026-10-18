<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * @return list<list<string|null>>
 */
function overviewCsv(string $query = ''): array
{
    $response = test()->get('/api/reports/passports?view=overview&medical_status=all&export=csv'.$query)->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('all-passports-01-05-2026.csv');

    return array_map('str_getcsv', explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
}

describe('All Passports CSV export', function () {
    it('downloads every passport with the overview columns and the latest status', function () {
        $company = Company::factory()->create(['name' => 'Alpha Builders', 'country_code' => 'MY']);
        $reference = Reference::factory()->agency()->create(['name' => 'Sea Star']);
        PassportEntry::factory()->create([
            'passport_name' => 'MD RAHIM',
            'passport_number' => 'A1234567',
            'company_id' => $company->id,
            'reference_id' => $reference->id,
            'date_of_birth' => '1990-05-17',
            'passport_received_date' => '2026-01-15',
            'passport_expiry_date' => '2035-01-01',
            'medical_slip_date' => null,
        ]);
        PassportEntry::factory()->count(20)->create(['passport_received_date' => '2026-01-10', 'passport_expiry_date' => '2035-01-01']);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $lines = overviewCsv();

        expect($lines[0])->toBe([
            'Passport name', 'Passport number', 'Date of birth', 'Country', 'Passport expiry date',
            'Company', 'Company country', 'Reference', 'Received date', 'Entered by', 'Latest status',
        ])
            // Every row, not one page.
            ->and($lines)->toHaveCount(22);

        $row = collect($lines)->firstWhere(0, 'MD RAHIM');
        expect([$row[1], $row[2], $row[4], $row[5], $row[7], $row[8]])
            ->toBe(['A1234567', '17-05-1990', '01-01-2035', 'Alpha Builders', 'Sea Star', '15-01-2026'])
            ->and($row[10])->not->toBe('');
    });

    it('uses the same searches as the list', function () {
        PassportEntry::factory()->create(['passport_name' => 'MD RAHIM', 'passport_expiry_date' => '2035-01-01']);
        PassportEntry::factory()->create(['passport_name' => 'MD KARIM', 'passport_expiry_date' => '2035-01-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $lines = overviewCsv('&q=rahim');

        expect($lines)->toHaveCount(2)->and($lines[1][0])->toBe('MD RAHIM');
    });

    it('is only for the overview', function () {
        PassportEntry::factory()->create(['passport_expiry_date' => '2035-01-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // Without view=overview, export is ignored and the JSON list comes back.
        $this->getJson('/api/data-entry/passports?export=csv')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/reports/passports?view=overview&export=pdf')->assertUnprocessable()->assertJsonValidationErrors('export');
    });
});

describe('company passports CSV export', function () {
    it('downloads every passport of the company, newest first, in the All Passports columns', function () {
        $company = Company::factory()->create(['name' => 'Alpha Builders Sdn Bhd', 'country_code' => 'MY']);
        PassportEntry::factory()->count(18)->create(['company_id' => $company->id, 'passport_expiry_date' => '2035-01-01']);
        PassportEntry::factory()->create(['company_id' => $company->id, 'passport_name' => 'NEWEST ONE', 'passport_expiry_date' => '2035-01-01', 'created_at' => now()->addMinute()]);
        PassportEntry::factory()->create(['passport_name' => 'OTHER COMPANY', 'passport_expiry_date' => '2035-01-01']);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $response = $this->get("/api/reports/companies/{$company->id}/passports?export=csv")->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('company-alpha-builders-sdn-bhd-passports-01-05-2026.csv');
        $lines = array_map('str_getcsv', explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        expect($lines[0][0])->toBe('Passport name')
            ->and(end($lines[0]))->toBe('Latest status')
            ->and($lines)->toHaveCount(20)
            ->and($lines[1][0])->toBe('NEWEST ONE')
            ->and(array_column($lines, 0))->not->toContain('OTHER COMPANY')
            ->and(array_unique(array_column(array_slice($lines, 1), 5)))->toBe(['Alpha Builders Sdn Bhd']);
    });
});
