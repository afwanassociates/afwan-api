<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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

function something()
{
    // ..
}
