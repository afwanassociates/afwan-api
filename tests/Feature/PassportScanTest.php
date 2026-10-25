<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use App\Services\PassportScanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(PassportScanService::DISK);
});

/**
 * A full passport form with the given scan (or none).
 *
 * @return array<string, mixed>
 */
function scanForm(UploadedFile|string|null $scan, string $number = 'SC1234567'): array
{
    return array_filter([
        'passport_name' => 'Md Karim Ali',
        'passport_number' => $number,
        'country_code' => 'BD',
        'date_of_birth' => '1995-04-12',
        'reference_id' => Reference::factory()->create()->id,
        'company_id' => Company::factory()->create()->id,
        'passport_received_date' => now()->subDay()->toDateString(),
        'passport_expiry_date' => now()->addYears(5)->toDateString(),
        'scan' => $scan,
    ], fn ($value) => $value !== null);
}

/**
 * The response must never reveal where the file is stored.
 */
function assertNoScanPath(TestResponse $response): TestResponse
{
    expect($response->getContent())->not->toContain('passport-scans')
        ->and($response->getContent())->not->toContain('scan_path');

    return $response;
}

describe('create', function () {
    it('requires the scan', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', scanForm(null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scan' => 'Please upload the passport scan copy.']);

        expect(PassportEntry::count())->toBe(0);
    });

    it('rejects a wrong type, a spoofed file and an oversized file', function (Closure $scan) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', scanForm($scan()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scan');

        expect(PassportEntry::count())->toBe(0)
            ->and(Storage::disk(PassportScanService::DISK)->allFiles())->toBe([]);
    })->with([
        'gif' => fn () => UploadedFile::fake()->createWithContent('passport.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"),
        'text' => fn () => UploadedFile::fake()->createWithContent('passport.txt', 'just text'),
        'script renamed to .jpg' => fn () => UploadedFile::fake()->createWithContent('passport.jpg', '<?php echo "hi";'),
        'html renamed to .pdf' => fn () => UploadedFile::fake()->createWithContent('passport.pdf', '<html><body>x</body></html>'),
        'pdf content named .jpg' => fn () => fakeScan('pdf', 'passport.jpg'),
        'png content named .pdf' => fn () => fakeScan('png', 'passport.pdf'),
        'over 5 MB' => fn () => fakeScan('pdf', padKb: 5121),
        'not a file' => fn () => 'passport.jpg',
    ]);

    it('stores a valid JPG, PNG or PDF on the private disk under a random name', function (string $type, string $mime) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->postJson('/api/data-entry/passports', scanForm(fakeScan($type, "My Passport Scan.{$type}")))
            ->assertCreated()
            ->assertJsonPath('data.has_scan', true)
            ->assertJsonPath('data.scan_original_name', "My Passport Scan.{$type}")
            ->assertJsonPath('data.scan_mime', $mime)
            ->assertJsonMissingPath('data.scan_path');
        assertNoScanPath($response);

        $passport = PassportEntry::sole();
        $extension = $type === 'jpg' ? 'jpg' : $type;

        expect($passport->scan_path)->toMatch("#^passport-scans/{$passport->id}/[0-9a-f-]{36}\\.{$extension}$#")
            ->and($passport->scan_path)->not->toContain('My Passport')
            ->and($passport->scan_size)->toBeGreaterThan(0)
            ->and($response->json('data.scan_size'))->toBe($passport->scan_size)
            ->and($response->json('data.scan_uploaded_at'))->not->toBeNull();

        Storage::disk(PassportScanService::DISK)->assertExists($passport->scan_path);
    })->with([
        'jpg' => ['jpg', 'image/jpeg'],
        'png' => ['png', 'image/png'],
        'pdf' => ['pdf', 'application/pdf'],
    ]);

    it('accepts a JPEG with the .jpeg extension and stores it as .jpg', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', scanForm(fakeScan('jpg', 'scan.JPEG')))->assertCreated();

        expect(PassportEntry::sole()->scan_path)->toEndWith('.jpg');
    });
});

describe('update', function () {
    it('requires a scan while the passport has none', function () {
        // Data entry edits only the passports they entered.
        Sanctum::actingAs($user = User::factory()->dataEntry()->create());
        $passport = PassportEntry::factory()->withoutScan()->create(['created_by' => $user->id]);

        $this->patchJson("/api/data-entry/passports/{$passport->id}", ['passport_name' => 'Renamed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scan' => 'Please upload the passport scan copy.']);

        $this->patchJson("/api/data-entry/passports/{$passport->id}", ['passport_name' => 'Renamed', 'scan' => fakeScan('png')])
            ->assertOk()
            ->assertJsonPath('data.passport_name', 'RENAMED')
            ->assertJsonPath('data.has_scan', true)
            ->assertJsonPath('data.scan_mime', 'image/png');
    });

    it('keeps the scan optional once there is one, and replaces it when sent', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson('/api/data-entry/passports', scanForm(fakeScan('jpg')))->assertCreated();
        $passport = PassportEntry::sole();
        $oldPath = $passport->scan_path;

        $this->patchJson("/api/data-entry/passports/{$passport->id}", ['passport_name' => 'No New Scan'])
            ->assertOk()
            ->assertJsonPath('data.scan_mime', 'image/jpeg');

        assertNoScanPath($this->patchJson("/api/data-entry/passports/{$passport->id}", ['scan' => fakeScan('pdf')]))
            ->assertOk()
            ->assertJsonPath('data.scan_mime', 'application/pdf');

        $newPath = $passport->fresh()->scan_path;

        expect($newPath)->not->toBe($oldPath);
        Storage::disk(PassportScanService::DISK)->assertMissing($oldPath);
        Storage::disk(PassportScanService::DISK)->assertExists($newPath);
    });

    it('rejects a bad replacement and keeps the old scan', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson('/api/data-entry/passports', scanForm(fakeScan('jpg')))->assertCreated();
        $passport = PassportEntry::sole();

        $this->patchJson("/api/data-entry/passports/{$passport->id}", ['scan' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scan');

        expect($passport->fresh()->scan_path)->toBe($passport->scan_path);
        Storage::disk(PassportScanService::DISK)->assertExists($passport->scan_path);
    });
});

describe('upload endpoint', function () {
    it('uploads, then replaces and deletes the old file, returning the passport details', function (string $role) {
        $passport = PassportEntry::factory()->withoutScan()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        assertNoScanPath($this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan('jpg')]))
            ->assertOk()
            ->assertJsonPath('data.id', $passport->id)
            ->assertJsonPath('data.has_scan', true)
            ->assertJsonStructure(['data' => ['workflow', 'current_medical', 'scan_original_name', 'scan_size', 'scan_uploaded_at']]);

        $first = $passport->fresh()->scan_path;

        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan('png')])
            ->assertOk()
            ->assertJsonPath('data.scan_mime', 'image/png');

        Storage::disk(PassportScanService::DISK)->assertMissing($first);
        Storage::disk(PassportScanService::DISK)->assertExists($passport->fresh()->scan_path);
        expect(Storage::disk(PassportScanService::DISK)->allFiles("passport-scans/{$passport->id}"))->toHaveCount(1);
    })->with(['dataEntry']);

    it('refuses admins: passport entry is the data entry team\'s alone', function (string $role) {
        $passport = PassportEntry::factory()->withoutScan()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan('jpg')])->assertForbidden();
        $this->get("/api/data-entry/passports/{$passport->id}/scan")->assertForbidden();
    })->with(['admin', 'superAdmin']);

    it('validates the upload', function () {
        $passport = PassportEntry::factory()->withoutScan()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scan');
        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan('pdf', padKb: 5121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scan' => 'The scan may not be larger than 5 MB.']);
    });

    it('rejects accounts and guests', function () {
        $passport = PassportEntry::factory()->withoutScan()->create();

        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan()])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/scan", ['scan' => fakeScan()])->assertForbidden();

        expect($passport->fresh()->hasScan())->toBeFalse();
    });
});

describe('streaming', function () {
    /**
     * A passport with an uploaded PDF scan.
     */
    function scannedPassport(): PassportEntry
    {
        $passport = PassportEntry::factory()->withoutScan()->create();
        app(PassportScanService::class)->store($passport, fakeScan('pdf', 'Karim passport.pdf'));

        return $passport->fresh();
    }

    it('streams the scan inline, or as a download, never cached', function (string $role) {
        $passport = scannedPassport();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $inline = $this->get("/api/data-entry/passports/{$passport->id}/scan")->assertOk();

        expect($inline->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($inline->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and($inline->headers->get('Cache-Control'))->toContain('no-store')
            ->and($inline->streamedContent())->toStartWith('%PDF-1.4');

        $download = $this->get("/api/data-entry/passports/{$passport->id}/scan?download=1")->assertOk();

        expect($download->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($download->headers->get('Content-Disposition'))->toContain('Karim passport.pdf');
    })->with(['dataEntry']);

    it('rejects accounts and guests', function () {
        $passport = scannedPassport();

        $this->getJson("/api/data-entry/passports/{$passport->id}/scan")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson("/api/data-entry/passports/{$passport->id}/scan")->assertForbidden();
    });

    it('returns 404 with a clear message when there is no scan', function () {
        $none = PassportEntry::factory()->withoutScan()->create();
        // Scan details but the file is gone from the disk.
        $missing = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$none->id}/scan")
            ->assertNotFound()
            ->assertJsonPath('message', 'This passport has no scan copy yet.');
        $this->getJson("/api/data-entry/passports/{$missing->id}/scan")->assertNotFound();
    });
});

describe('responses', function () {
    it('includes the scan fields on every passport details response, never the path', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $created = assertNoScanPath($this->postJson('/api/data-entry/passports', scanForm(fakeScan('jpg'))))->assertCreated();
        $id = $created->json('data.id');

        $details = [
            'store' => $created,
            'show' => $this->getJson("/api/data-entry/passports/{$id}"),
            'update' => $this->patchJson("/api/data-entry/passports/{$id}", ['passport_name' => 'Edited']),
            'medical slip' => $this->patchJson("/api/data-entry/passports/{$id}/medical-slip", ['medical_slip_date' => '2026-01-01']),
            'medical date' => $this->patchJson("/api/data-entry/passports/{$id}/medical-date", ['medical_date' => now()->subDay()->toDateString()]),
            'scan upload' => $this->postJson("/api/data-entry/passports/{$id}/scan", ['scan' => fakeScan('png')]),
        ];

        foreach ($details as $name => $response) {
            assertNoScanPath($response->assertSuccessful());
            expect($response->json('data'))->toHaveKeys(['has_scan', 'scan_original_name', 'scan_mime', 'scan_size', 'scan_uploaded_at'], $name)
                ->and($response->json('data.has_scan'))->toBeTrue();
        }
    });

    it('includes only has_scan on list responses', function () {
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $lists = [
            'passport list' => $this->getJson('/api/data-entry/passports'),
            'overview' => $this->getJson('/api/data-entry/passports?view=overview'),
            'passport entered mode' => $this->getJson('/api/data-entry/passports?mode=passport_entered'),
            'company report' => $this->getJson("/api/reports/companies/{$passport->company_id}/passports"),
        ];

        foreach ($lists as $name => $response) {
            assertNoScanPath($response->assertOk());
            expect($response->json('data.0.has_scan'))->toBeTrue($name)
                ->and($response->json('data.0'))->not->toHaveKeys(['scan_original_name', 'scan_mime', 'scan_size', 'scan_uploaded_at']);
        }
    });

    it('still loads old passports without a scan', function () {
        $old = PassportEntry::factory()->withoutScan()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$old->id}")
            ->assertOk()
            ->assertJsonPath('data.has_scan', false)
            ->assertJsonPath('data.scan_original_name', null)
            ->assertJsonPath('data.scan_mime', null)
            ->assertJsonPath('data.scan_size', null)
            ->assertJsonPath('data.scan_uploaded_at', null);
        $this->getJson('/api/data-entry/passports')->assertOk()->assertJsonPath('data.0.has_scan', false);
        $this->getJson('/api/data-entry/passports?view=overview')->assertOk()->assertJsonPath('data.0.has_scan', false);
    });
});

describe('medical slip number', function () {
    it('is no longer accepted, stored or returned', function () {
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", [
            'medical_slip_date' => '2026-04-15',
            'medical_slip_no' => 'MG-2026-0042',
        ])->assertOk();

        expect($response->json('data.medical_slip'))->toBe(['date' => '2026-04-15', 'medical_center' => null])
            ->and($response->getContent())->not->toContain('MG-2026-0042')
            ->and(Schema::hasColumn('passport_entries', 'medical_slip_no'))->toBeFalse();
    });
});
