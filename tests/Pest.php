<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeAll(function () {
        /*
        | Safety check: RefreshDatabase wipes the database, so refuse to run unless the
        | tests point at a database whose name ends with "_test". This runs before
        | setUp(), so before any migration. A cached config would make Laravel
        | ignore the phpunit.xml values, so refuse that as well.
        */
        $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests: DB_DATABASE \"{$database}\" does not end with \"_test\".");
        }

        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Refusing to run tests: configuration is cached. Run "php artisan config:clear" first.');
        }
    })
    // Uploads (passport scans) go to a temporary fake disk, never storage/app/private.
    ->beforeEach(fn () => Storage::fake('local'))
    ->in('Feature');

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
