<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Services\PassportScanService;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes all passport / business data and keeps the system and setup data.
 *
 * The lists below come from the migrations (database/migrations). Every table
 * in the database must be in one of them: an unknown table (e.g. from a new
 * migration) stops the command before anything changes, so a new business
 * table is never left behind and a new setup table is never wiped by guess.
 */
#[Signature('app:reset-business-data
    {--force : Skip the confirmation (in production the database name is still asked)}
    {--dry-run : Only print the lists and row counts; change nothing}
    {--keep-companies : Keep the companies and references tables}')]
#[Description('Remove all passport / business data; keep users, settings, countries and lookups')]
class ResetBusinessData extends Command
{
    /**
     * System and setup tables: never deleted or truncated.
     *
     * @var list<string>
     */
    public const KEEP = [
        // Accounts and auth
        'users',
        'password_reset_tokens',
        'sessions',
        'personal_access_tokens',
        // Framework
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
        // Setup and lookups
        'countries',
        'app_settings',
        'medical_centers',
    ];

    /**
     * Business data (children before parents).
     *
     * @var list<string>
     */
    public const CLEAR = [
        'passport_step_records',
        'medical_records',
        'passport_entries',
        'companies',
        'references',
    ];

    /**
     * Kept with --keep-companies.
     *
     * @var list<string>
     */
    public const COMPANY_TABLES = ['companies', 'references'];

    /**
     * Tables checked row by row after the reset (key = ordering column).
     *
     * @var array<string, string>
     */
    protected const PROTECTED = [
        'users' => 'id',
        'countries' => 'code',
        'app_settings' => 'key',
        'medical_centers' => 'id',
        'companies' => 'id',
        'references' => 'id',
    ];

    /**
     * Passport scan copies on the private disk (storage/app/private).
     */
    public const SCAN_FOLDER = 'passport-scans';

    /**
     * The country that must exist (the default company and passport country).
     */
    protected const DEFAULT_COUNTRY = 'MY';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $existing = Schema::getTableListing($database, false);
        $keep = $this->keepList();
        $clear = $this->clearList();

        $this->line("Database: <comment>{$database}</comment>".($this->option('dry-run') ? ' <info>(dry run)</info>' : ''));
        $this->printLists($keep, $clear, $existing, 'BEFORE');

        $unknown = array_values(array_diff($existing, self::KEEP, self::CLEAR));
        if ($unknown !== []) {
            $this->error('Unknown table(s): '.implode(', ', $unknown).'. Add them to the KEEP or CLEAR list in '.self::class.' first. Nothing was changed.');

            return self::FAILURE;
        }

        $missing = array_values(array_diff($clear, $existing));
        if ($missing !== []) {
            $this->error('Table(s) to clear do not exist: '.implode(', ', $missing).'. Run the migrations first. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        // The setup the reset must keep has to be there before anything is removed.
        $problems = $this->setupProblems();
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->error('Refusing to reset. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->confirmed($database)) {
            return self::FAILURE;
        }

        $snapshot = $this->snapshot($keep, $existing);

        $this->truncate($clear);
        $removed = $this->clearScans();
        $this->line("Deleted {$removed} file(s) under ".self::SCAN_FOLDER.'/.');

        $this->call('optimize:clear');

        $this->printLists($keep, $clear, $existing, 'AFTER');

        return $this->verify($snapshot, $clear);
    }

    /**
     * @return list<string>
     */
    protected function keepList(): array
    {
        return $this->option('keep-companies')
            ? [...self::KEEP, ...self::COMPANY_TABLES]
            : self::KEEP;
    }

    /**
     * @return list<string>
     */
    protected function clearList(): array
    {
        return $this->option('keep-companies')
            ? array_values(array_diff(self::CLEAR, self::COMPANY_TABLES))
            : self::CLEAR;
    }

    /**
     * Both lists with their row counts, and the scan files.
     *
     * @param  list<string>  $keep
     * @param  list<string>  $clear
     * @param  list<string>  $existing
     */
    protected function printLists(array $keep, array $clear, array $existing, string $when): void
    {
        $rows = [];
        foreach (['KEEP' => $keep, 'CLEAR' => $clear] as $list => $tables) {
            foreach ($tables as $table) {
                $rows[] = [$list, $table, in_array($table, $existing, true) ? DB::table($table)->count() : '(no table)'];
            }
        }
        $rows[] = ['CLEAR', 'files: '.self::SCAN_FOLDER.'/', count($this->disk()->allFiles(self::SCAN_FOLDER))];

        $this->newLine();
        $this->line("<info>Row counts {$when}</info>");
        $this->table(['List', 'Table', 'Rows'], $rows);
    }

    /**
     * What the setup is missing: an active super admin and the default country.
     *
     * @return list<string>
     */
    protected function setupProblems(): array
    {
        $problems = [];

        if (! DB::table('users')->where('role', UserRole::SuperAdmin->value)->exists()) {
            $problems[] = 'No super_admin user exists.';
        }

        if (! DB::table('countries')->where('code', self::DEFAULT_COUNTRY)->exists()) {
            $problems[] = 'The default country (Malaysia, '.self::DEFAULT_COUNTRY.') does not exist.';
        }

        foreach ([Settings::DEFAULT_COMPANY_COUNTRY, Settings::DEFAULT_PASSPORT_COUNTRY] as $key) {
            $code = DB::table('app_settings')->where('key', $key)->value('value');
            if ($code !== null && ! DB::table('countries')->where('code', $code)->exists()) {
                $problems[] = "The setting {$key} ({$code}) points to a country that does not exist.";
            }
        }

        return $problems;
    }

    /**
     * Production: always type the database name (also with --force).
     * Elsewhere: confirm, unless --force.
     */
    protected function confirmed(string $database): bool
    {
        if ($this->laravel->environment('production')) {
            $typed = (string) $this->ask("PRODUCTION. Type the database name ({$database}) to delete all business data");

            if ($typed !== $database) {
                $this->error('The database name does not match. Nothing was changed.');

                return false;
            }

            return true;
        }

        if ($this->option('force')) {
            return true;
        }

        if (! $this->confirm("Delete all business data in \"{$database}\"? This cannot be undone.")) {
            $this->warn('Cancelled. Nothing was changed.');

            return false;
        }

        return true;
    }

    /**
     * A fingerprint of every kept table that must not change.
     *
     * @param  list<string>  $keep
     * @param  list<string>  $existing
     * @return array<string, array{count: int, hash: string}>
     */
    protected function snapshot(array $keep, array $existing): array
    {
        $snapshot = [];
        foreach (self::PROTECTED as $table => $orderBy) {
            if (! in_array($table, $keep, true) || ! in_array($table, $existing, true)) {
                continue;
            }
            $rows = DB::table($table)->orderBy($orderBy)->get();
            $snapshot[$table] = ['count' => $rows->count(), 'hash' => md5((string) json_encode($rows))];
        }

        return $snapshot;
    }

    /**
     * TRUNCATE every table in the list; foreign key checks are always turned back on.
     *
     * @param  list<string>  $tables
     */
    protected function truncate(array $tables): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
                $this->line("Truncated {$table}.");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Deletes everything under the scan folder and keeps the folder itself.
     */
    protected function clearScans(): int
    {
        $disk = $this->disk();
        $files = $disk->allFiles(self::SCAN_FOLDER);

        $disk->delete($files);
        foreach ($disk->directories(self::SCAN_FOLDER) as $directory) {
            $disk->deleteDirectory($directory);
        }
        $disk->makeDirectory(self::SCAN_FOLDER);

        return count($files);
    }

    /**
     * Checks that the cleared tables and scans are empty and the kept setup is
     * unchanged.
     *
     * @param  array<string, array{count: int, hash: string}>  $snapshot
     * @param  list<string>  $clear
     */
    protected function verify(array $snapshot, array $clear): int
    {
        $errors = $this->setupProblems();

        foreach ($snapshot as $table => $before) {
            $after = $this->snapshot([$table], [$table])[$table];
            if ($after !== $before) {
                $errors[] = "The kept table {$table} changed ({$before['count']} rows before, {$after['count']} after).";
            }
        }

        foreach ($clear as $table) {
            if (DB::table($table)->exists()) {
                $errors[] = "The table {$table} is not empty.";
            }
        }

        if ($this->disk()->allFiles(self::SCAN_FOLDER) !== []) {
            $errors[] = 'Files are left under '.self::SCAN_FOLDER.'/.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error("VERIFY FAILED: {$error}");
            }

            return self::FAILURE;
        }

        $this->info('Verified: business data removed; users (with a super_admin), countries (Malaysia), settings and lookups unchanged.');

        return self::SUCCESS;
    }

    /**
     * The private disk the scans are stored on (see PassportScanService).
     */
    protected function disk(): Filesystem
    {
        return Storage::disk(PassportScanService::DISK);
    }
}
