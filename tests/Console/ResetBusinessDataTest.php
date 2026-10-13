<?php

use App\Console\Commands\ResetBusinessData;
use App\Enums\StepStatus;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\Reference;
use App\Models\User;
use App\Services\PassportScanService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Console\OptimizeClearCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| app:reset-business-data removes the passport / business data and keeps the
| system and setup data. Scan files go to the fake disk (tests/Pest.php), and
| optimize:clear is replaced by a stand-in so no real cache or view file is
| touched.
*/

/** The kept setup rows, as they are now (to compare after the command). */
function setupRows(): array
{
    return [
        'users' => DB::table('users')->orderBy('id')->get()->toArray(),
        'countries' => DB::table('countries')->orderBy('code')->get()->toArray(),
        'app_settings' => DB::table('app_settings')->orderBy('key')->get()->toArray(),
        'medical_centers' => DB::table('medical_centers')->orderBy('id')->get()->toArray(),
    ];
}

/** Row counts of the business tables. */
function businessCounts(): array
{
    return collect(ResetBusinessData::CLEAR)
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

beforeEach(function () {
    // optimize:clear stand-in: records that it ran.
    $this->optimized = false;
    $test = $this;
    $this->app->instance(OptimizeClearCommand::class, new class($test) extends Command
    {
        protected $signature = 'optimize:clear';

        public function __construct(private object $test)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->test->optimized = true;

            return self::SUCCESS;
        }
    });

    // Users of every role.
    $this->superAdmin = User::factory()->superAdmin()->create();
    User::factory()->admin()->create();
    $dataEntry = User::factory()->dataEntry()->create();
    User::factory()->accounts()->create();

    // Setup: a country, a setting and a medical centre (on top of the migrations' rows).
    Country::factory()->create();
    AppSetting::query()->create(['key' => 'test_setting', 'value' => 'kept']);
    $center = MedicalCenter::factory()->create();

    // Business data: a passport with a medical and step records, its company and reference.
    $this->passport = PassportEntry::factory()->createdBy($dataEntry)
        ->create(['medical_center_id' => $center->id]);
    MedicalRecord::factory()->for($this->passport, 'passportEntry')->createdBy($dataEntry)->create();
    PassportStepRecord::factory()->for($this->passport, 'passportEntry')->createdBy($dataEntry)
        ->step('calling')->status(StepStatus::Completed)->create();
    PassportStepRecord::factory()->for($this->passport, 'passportEntry')->createdBy($dataEntry)
        ->step('visa')->status(StepStatus::InProcess)->create();

    // Scan files (fake disk).
    $disk = Storage::disk(PassportScanService::DISK);
    $disk->put("passport-scans/{$this->passport->id}/scan.jpg", 'jpg');
    $disk->put('passport-scans/99/old.pdf', 'pdf');
    $disk->put('other/keep.txt', 'not a scan');
});

it('removes the business data and scans and keeps users, countries, settings and lookups', function () {
    $setup = setupRows();
    expect(businessCounts())->each->toBeGreaterThan(0);

    $this->artisan('app:reset-business-data', ['--force' => true])
        ->expectsOutputToContain('Row counts BEFORE')
        ->expectsOutputToContain('Row counts AFTER')
        ->expectsOutputToContain('Truncated passport_entries.')
        ->expectsOutputToContain('Deleted 2 file(s) under passport-scans/.')
        ->expectsOutputToContain('Verified:')
        ->assertSuccessful();

    expect(businessCounts())->each->toBe(0);
    expect(setupRows())->toEqual($setup);
    expect(User::query()->where('role', 'super_admin')->exists())->toBeTrue();
    expect(Country::query()->whereKey('MY')->exists())->toBeTrue();

    $disk = Storage::disk(PassportScanService::DISK);
    expect($disk->allFiles('passport-scans'))->toBe([])
        ->and($disk->directoryExists('passport-scans'))->toBeTrue()
        ->and($disk->exists('other/keep.txt'))->toBeTrue();

    expect($this->optimized)->toBeTrue();
    // Foreign key checks are back on.
    expect(fn () => DB::table('medical_records')->insert([
        'passport_entry_id' => 999999,
        'medical_date' => '2026-10-01',
        'result' => 'fit',
        'created_by' => $this->superAdmin->id,
    ]))->toThrow(QueryException::class);
});

it('prints the KEEP and CLEAR lists with their row counts', function () {
    $this->artisan('app:reset-business-data', ['--dry-run' => true])
        ->expectsTable(['List', 'Table', 'Rows'], [
            ['KEEP', 'users', 4],
            ['KEEP', 'password_reset_tokens', 0],
            ['KEEP', 'sessions', 0],
            ['KEEP', 'personal_access_tokens', 0],
            ['KEEP', 'cache', 0],
            ['KEEP', 'cache_locks', 0],
            ['KEEP', 'jobs', 0],
            ['KEEP', 'job_batches', 0],
            ['KEEP', 'failed_jobs', 0],
            ['KEEP', 'migrations', DB::table('migrations')->count()],
            ['KEEP', 'countries', Country::query()->count()],
            ['KEEP', 'app_settings', AppSetting::query()->count()],
            ['KEEP', 'medical_centers', MedicalCenter::query()->count()],
            ['CLEAR', 'passport_step_records', 2],
            ['CLEAR', 'medical_records', 1],
            ['CLEAR', 'passport_entries', 1],
            ['CLEAR', 'companies', 1],
            ['CLEAR', 'references', 1],
            ['CLEAR', 'files: passport-scans/', 2],
        ])
        ->assertSuccessful();
});

it('changes nothing with --dry-run', function () {
    $setup = setupRows();
    $counts = businessCounts();

    $this->artisan('app:reset-business-data', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->doesntExpectOutputToContain('Truncated')
        ->assertSuccessful();

    expect(businessCounts())->toBe($counts)
        ->and(setupRows())->toEqual($setup)
        ->and(Storage::disk(PassportScanService::DISK)->allFiles('passport-scans'))->toHaveCount(2)
        ->and($this->optimized)->toBeFalse();
});

it('keeps companies and references with --keep-companies', function () {
    $companies = Company::query()->orderBy('id')->get()->toArray();
    $references = Reference::query()->orderBy('id')->get()->toArray();

    $this->artisan('app:reset-business-data', ['--force' => true, '--keep-companies' => true])
        ->doesntExpectOutputToContain('Truncated companies.')
        ->doesntExpectOutputToContain('Truncated references.')
        ->assertSuccessful();

    expect(Company::query()->orderBy('id')->get()->toArray())->toEqual($companies)
        ->and(Reference::query()->orderBy('id')->get()->toArray())->toEqual($references)
        ->and(PassportEntry::query()->count())->toBe(0)
        ->and(PassportStepRecord::query()->withTrashed()->count())->toBe(0)
        ->and(MedicalRecord::query()->withTrashed()->count())->toBe(0);
});

it('asks for confirmation without --force and changes nothing when declined', function () {
    $counts = businessCounts();

    $this->artisan('app:reset-business-data')
        ->expectsConfirmation('Delete all business data in "'.DB::connection()->getDatabaseName().'"? This cannot be undone.', 'no')
        ->expectsOutputToContain('Cancelled. Nothing was changed.')
        ->assertFailed();

    expect(businessCounts())->toBe($counts);
});

it('refuses to run in production unless the database name is typed, even with --force', function () {
    $this->app->instance('env', 'production');
    $database = DB::connection()->getDatabaseName();
    $counts = businessCounts();
    $question = "PRODUCTION. Type the database name ({$database}) to delete all business data";

    $this->artisan('app:reset-business-data', ['--force' => true])
        ->expectsQuestion($question, 'yes')
        ->expectsOutputToContain('The database name does not match. Nothing was changed.')
        ->assertFailed();

    expect(businessCounts())->toBe($counts)
        ->and(Storage::disk(PassportScanService::DISK)->allFiles('passport-scans'))->toHaveCount(2);

    // The right name runs it.
    $this->artisan('app:reset-business-data', ['--force' => true])
        ->expectsQuestion($question, $database)
        ->assertSuccessful();

    expect(businessCounts())->each->toBe(0);
});

it('refuses an unknown table (not in KEEP or CLEAR) before changing anything', function () {
    Schema::create('passport_notes', fn ($table) => $table->id());
    $counts = businessCounts();

    $this->artisan('app:reset-business-data', ['--force' => true])
        ->expectsOutputToContain('Unknown table(s): passport_notes.')
        ->assertFailed();

    expect(businessCounts())->toBe($counts);
});

it('refuses when there is no super admin (nothing is removed)', function () {
    User::query()->where('role', 'super_admin')->delete();
    $counts = businessCounts();

    $this->artisan('app:reset-business-data', ['--force' => true])
        ->expectsOutputToContain('No super_admin user exists.')
        ->assertFailed();

    expect(businessCounts())->toBe($counts);
});
