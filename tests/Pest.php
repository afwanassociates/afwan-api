<?php

use App\Models\Company;
use App\Models\MedicalCenter;
use App\Models\Party;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;
use App\Models\User;
use Database\Factories\PassportEntryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

// No step moves forward without an approved recruitment agent: test passports get the Own one.
PassportEntryFactory::$approvedAgentByDefault = true;

/*
| Safety check: the tests wipe the database, so refuse to run unless they point
| at a database whose name ends with "_test". This runs before setUp(), so
| before any migration. A cached config would make Laravel ignore the
| phpunit.xml values, so refuse that as well.
*/
function refuseUnsafeTestDatabase(): void
{
    $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));

    if (! str_ends_with($database, '_test')) {
        throw new RuntimeException("Refusing to run tests: DB_DATABASE \"{$database}\" does not end with \"_test\".");
    }

    if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Refusing to run tests: configuration is cached. Run "php artisan config:clear" first.');
    }
}

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeAll(fn () => refuseUnsafeTestDatabase())
    // Uploads (passport scans) go to a temporary fake disk, never storage/app/private.
    ->beforeEach(fn () => Storage::fake('local'))
    ->in('Feature');

/*
| Console commands that TRUNCATE tables: MySQL commits the open transaction on
| TRUNCATE, so RefreshDatabase cannot roll these tests back. Each test starts
| from and ends with a freshly migrated database (with the rows the migrations
| seed) instead; files still go to the fake disk.
*/
pest()->extend(TestCase::class)
    ->beforeAll(fn () => refuseUnsafeTestDatabase())
    ->beforeEach(function () {
        Storage::fake('local');
        // Feature tests roll back, so a migrated database is still fresh.
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = true;
        }
    })
    ->afterEach(function () {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    })
    ->in('Console');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A passport scan upload with real file content (finfo reads the type from
 * it): "jpg", "png" or "pdf". $padKb pads the file to roughly that size.
 */
function fakeScan(string $type = 'jpg', ?string $name = null, int $padKb = 1): UploadedFile
{
    $content = match ($type) {
        'jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", $padKb * 1024)."\xFF\xD9",
        'png' => "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xDE".str_repeat("\x00", $padKb * 1024),
        'pdf' => "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n".str_repeat(' ', $padKb * 1024)."\n%%EOF\n",
    };

    return UploadedFile::fake()->createWithContent($name ?? "passport.{$type}", $content);
}

/**
 * Record a medical result the way the SPA does: set the passport's medical
 * date, then post the result (a fit one issued on the medical date and valid
 * for the usual 3 months). Returns the date response when that fails,
 * otherwise the result response.
 */
function recordMedical(int $passportId, string $medicalDate, string $result): TestResponse
{
    $date = test()->patchJson("/api/data-entry/passports/{$passportId}/medical-date", ['medical_date' => $medicalDate]);

    if (! $date->isOk()) {
        return $date;
    }

    $dates = $result === 'fit'
        ? ['issue_date' => $medicalDate, 'expiry_date' => Carbon::parse($medicalDate)->addMonthsNoOverflow(3)->toDateString()]
        : [];

    return test()->postJson("/api/data-entry/passports/{$passportId}/medical-result", ['result' => $result, ...$dates]);
}

/**
 * A medical slip payload with the required medical date and centre filled in when the
 * test doesn't send them: the passport's own medical date (or the slip date) and an
 * active centre.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function slipPayload(array $data, int $passportId): array
{
    if (($data['medical_slip_date'] ?? null) === null) {
        return $data;
    }

    $current = PassportEntry::query()->whereKey($passportId)->value('medical_date');
    $data['medical_date'] ??= $current ? substr((string) $current, 0, 10) : $data['medical_slip_date'];
    $data['medical_center_id'] ??= MedicalCenter::query()->where('is_active', true)->value('id') ?? MedicalCenter::factory()->create()->id;

    return $data;
}

/**
 * Approve every accounts entry, as the accounts head would (the approval rules
 * themselves are covered by tests/Feature/ApprovalTest.php).
 */
/**
 * The money summary as the accounts head sees it (accounts users don't get the
 * balances), then back to the user who was acting. Cash settings
 * (/api/accounts/cash-settings) add the pending summary.
 */
function financeSummary(string $url = '/api/finance/summary'): TestResponse
{
    $previous = auth('sanctum')->user();
    Sanctum::actingAs(User::factory()->accountsHead()->create());
    $response = test()->getJson($url);
    if ($previous) {
        Sanctum::actingAs($previous);
    }

    return $response;
}

function approveAllAccountsEntries(): void
{
    foreach (['cash_receipts', 'cash_payments', 'cash_transfers', 'party_transactions', 'owner_loan_bills'] as $table) {
        DB::table($table)->update(['status' => 'approved', 'approved_at' => now()]);
    }
}

/**
 * Put money in the bank (or mobile banking) with an approved receipt from a new
 * client, so a later payment or withdrawal does not overdraw it. Only this
 * receipt is approved. Returns its id.
 */
function fundAccount(float $amount, string $method = 'bank', string $date = '2026-10-01'): int
{
    $party = Party::factory()->create();
    $id = test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => 'client', 'party_id' => $party->id,
        'business_type' => 'recruitment', 'country_code' => 'MY',
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => $method,
        'bank_name' => 'Sonali', 'bank_branch' => 'Motijheel', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567',
        'mobile_provider' => 'bkash', 'mobile_number' => '01712345678',
    ])->assertCreated()->json('data.id');
    DB::table('cash_receipts')->where('id', $id)->update(['status' => 'approved', 'approved_at' => now()]);

    return $id;
}

/**
 * A recruitment cash receipt from the party paying one company's passports.
 *
 * @param  list<PassportEntry>  $passports
 * @return array<string, mixed>
 */
function paymentReportReceipt(Party $party, Company $company, int $amount, array $passports, bool $full = true): array
{
    return [
        'date' => '2026-10-10',
        'party_type' => 'client',
        'party_id' => $party->id,
        'receipt_type' => 'cash',
        'business_type' => 'recruitment',
        'country_code' => 'MY',
        'amount' => $amount,
        'payment_method' => 'cash',
        'companies' => [[
            'company_id' => $company->id,
            'amount' => $amount,
            'is_full_payment' => $full,
            'passport_ids' => array_map(fn (PassportEntry $p) => $p->id, $passports),
        ]],
    ];
}

/**
 * Give the passport an approved recruitment agent (the medical slip needs one).
 */
function approveAgentFor(PassportEntry|int $passport): PassportAgentAssignment
{
    $id = $passport instanceof PassportEntry ? $passport->id : $passport;
    $agent = RecruitmentAgent::query()->firstOrNew(['rl_number' => 'RL-TEST-1'], ['name' => 'Test Agent']);
    $agent->is_active = true;
    $agent->save();

    return PassportAgentAssignment::query()->firstOrCreate(
        ['passport_entry_id' => $id, 'status' => 'approved'],
        ['recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-01-01', 'approved_at' => now()],
    );
}
