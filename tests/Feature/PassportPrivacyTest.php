<?php

use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/*
| Passport numbers must not appear in logs, exception messages or error
| responses (other than the user's own validation error).
*/

it('turns a duplicate passport number that slips past validation into a 422 without the number', function () {
    PassportEntry::factory()->create(['passport_number' => 'ZX9876543']);

    // Two saves at the same moment: both pass validation, the unique index stops the second.
    $exception = rescue(fn () => PassportEntry::factory()->create(['passport_number' => 'ZX9876543']), fn ($e) => $e, report: false);
    expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class);

    Log::spy();
    report($exception);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['index'] === 'passport_entries_passport_number_unique'
            && ! str_contains(json_encode([$message, $context]), 'ZX9876543'),
    );
    Log::shouldNotHaveReceived('error');

    $response = app(ExceptionHandler::class)->render(Request::create('/api/data-entry/passports', 'POST'), $exception);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'message' => 'This passport number is already entered.',
            'errors' => ['passport_number' => ['This passport number is already entered.']],
        ]);
});

it('masks query bindings in database error messages', function () {
    $exception = rescue(
        fn () => DB::select('select * from passport_entries where passport_number = ? and no_such_column = 1', ['ZX9876543']),
        fn ($e) => $e,
        report: false,
    );

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getMessage())->not->toContain('ZX9876543')
        ->and((string) $exception)->not->toContain('ZX9876543');
});

it('does not echo the passport number in the duplicate validation error', function () {
    PassportEntry::factory()->create(['passport_number' => 'ZX9876543']);
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $response = $this->postJson('/api/data-entry/passports', passportPayload(['passport_number' => 'ZX9876543']))
        ->assertUnprocessable();

    expect($response->getContent())->not->toContain('ZX9876543');
});
