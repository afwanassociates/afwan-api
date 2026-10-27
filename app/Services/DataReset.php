<?php

namespace App\Services;

use App\Console\Commands\ResetBusinessData;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PharData;
use RuntimeException;

/**
 * "Delete all data" for the super admin (Settings → Danger zone).
 *
 * The data is split into groups that can be deleted separately. Users, roles, settings,
 * countries and medical centres are never touched, so everyone can still log in.
 *
 * A reset first writes a backup (a .tar.gz with data.json and the passport scans) to the
 * private disk, then deletes the rows in one transaction (all or nothing), then removes the
 * scan files. `php artisan app:restore-data-backup <file>` puts a backup back.
 */
class DataReset
{
    /**
     * The groups, with their tables in delete order (rows that point at others first).
     *
     * @var array<string, array{label: string, description: string, tables: list<string>, scans?: bool}>
     */
    public const GROUPS = [
        'passports' => [
            'label' => 'Passports & workflow',
            'description' => 'Passport entries, medical records, every workflow step and the uploaded passport scans.',
            'tables' => ['passport_step_records', 'medical_records', 'passport_entries'],
            'scans' => true,
        ],
        'companies' => [
            'label' => 'Companies & references',
            'description' => 'Employer companies and references (agents and individuals). Passports must be deleted too.',
            'tables' => ['companies', 'references'],
        ],
        'accounts' => [
            'label' => 'Accounts',
            'description' => 'Parties and owners, cash receipts, payments and transfers, ledgers, approvals and the cash opening balance.',
            'tables' => ['party_transactions', 'cash_receipts', 'cash_payments', 'cash_transfers', 'parties'],
        ],
    ];

    /** Passport scan copies on the private disk (same folder as the server reset command). */
    public const SCAN_FOLDER = ResetBusinessData::SCAN_FOLDER;

    /** The exact words the super admin types to confirm. */
    public const CONFIRMATION = 'DELETE ALL DATA';

    /** Backups on the private disk (storage/app/private). */
    public const BACKUP_FOLDER = 'data-backups';

    /** Backup file names: data-backup-2026-10-25-143005.tar.gz */
    public const BACKUP_PATTERN = '/^data-backup-\d{4}-\d{2}-\d{2}-\d{6}\.tar\.gz$/';

    /**
     * Every group with its row counts (and scan files), for the page.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $group) {
            $tables = array_map(fn (string $table) => [
                'name' => $table,
                'rows' => DB::table($table)->count(),
            ], $group['tables']);

            $groups[] = [
                'key' => $key,
                'label' => $group['label'],
                'description' => $group['description'],
                'tables' => $tables,
                'rows' => array_sum(array_column($tables, 'rows')),
                'files' => ($group['scans'] ?? false) ? count($this->disk()->allFiles(self::SCAN_FOLDER)) : null,
            ];
        }

        return $groups;
    }

    /**
     * Back up, then delete the chosen groups. Refused (422) if companies are chosen without
     * passports: passports point at companies.
     *
     * @param  list<string>  $groups
     * @return array{deleted: array<string, int>, files: int, backup: string}
     */
    public function reset(array $groups, User $user): array
    {
        $groups = array_values(array_intersect(array_keys(self::GROUPS), $groups));

        if (in_array('companies', $groups, true) && ! in_array('passports', $groups, true)) {
            throw ValidationException::withMessages([
                'groups' => 'Companies & references can only be deleted together with Passports & workflow, because passports belong to companies.',
            ]);
        }

        $tables = array_merge(...array_map(fn (string $key) => self::GROUPS[$key]['tables'], $groups));
        $withScans = in_array('passports', $groups, true);
        $withCash = in_array('accounts', $groups, true);

        $backup = $this->backup($tables, $withScans, $withCash, $groups, $user);

        $deleted = DB::transaction(function () use ($tables, $withCash) {
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = DB::table($table)->delete();
            }
            if ($withCash) {
                // The single settings row stays; its opening balance goes back to none.
                DB::table('cash_settings')->update(['opening_balance' => 0, 'opening_date' => null, 'updated_by' => null]);
            }

            return $counts;
        });

        // Files cannot be part of the transaction: remove them only once the rows are gone.
        $files = $withScans ? $this->clearScans() : 0;

        Log::warning('Data reset by super admin', [
            'user_id' => $user->id,
            'groups' => $groups,
            'deleted' => $deleted,
            'files' => $files,
            'backup' => $backup,
        ]);

        return ['deleted' => $deleted, 'files' => $files, 'backup' => $backup];
    }

    /**
     * The saved backups, newest first, with who made them and what they hold.
     *
     * @return list<array<string, mixed>>
     */
    public function backups(): array
    {
        $list = [];

        foreach ($this->disk()->files(self::BACKUP_FOLDER) as $path) {
            $name = basename($path);
            if (! preg_match(self::BACKUP_PATTERN, $name)) {
                continue;
            }

            $meta = $this->readMeta($this->disk()->path($path));
            $list[] = [
                'name' => $name,
                'size' => $this->disk()->size($path),
                'created_at' => $meta['created_at'] ?? null,
                'created_by' => $meta['created_by']['name'] ?? null,
                'groups' => $meta['groups'] ?? [],
                'rows' => $meta['rows'] ?? null,
                'files' => $meta['files'] ?? null,
            ];
        }

        usort($list, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    /**
     * The full path of a backup, or null if the name is not a backup that exists.
     */
    public function backupPath(string $name): ?string
    {
        if (! preg_match(self::BACKUP_PATTERN, $name) || ! $this->disk()->exists(self::BACKUP_FOLDER.'/'.$name)) {
            return null;
        }

        return $this->disk()->path(self::BACKUP_FOLDER.'/'.$name);
    }

    public function deleteBackup(string $name): bool
    {
        return $this->backupPath($name) !== null && $this->disk()->delete(self::BACKUP_FOLDER.'/'.$name);
    }

    /**
     * Put a backup back: every table in it must be empty (so nothing is overwritten).
     *
     * @return array{restored: array<string, int>, files: int}
     */
    public function restore(string $archivePath): array
    {
        return $this->withArchive($archivePath, fn (PharData $archive, string $path) => $this->restoreFrom($archive, $path));
    }

    /**
     * @return array{restored: array<string, int>, files: int}
     */
    /**
     * Backups made before the entry number existed: number those passports by id, after the
     * highest number the backup has. Rows that have a number keep it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function numberPassports(array $rows): array
    {
        $rows = collect($rows)->sortBy('id')->values();
        $next = (int) $rows->max('entry_no') + 1;

        return $rows->map(function (array $row) use (&$next) {
            return [...$row, 'entry_no' => $row['entry_no'] ?? $next++];
        })->all();
    }

    private function restoreFrom(PharData $archive, string $archivePath): array
    {
        $data = json_decode((string) file_get_contents($archive['data.json']->getPathname()), true, flags: JSON_THROW_ON_ERROR);

        // Parents first (the reverse of the delete order).
        $tables = array_reverse(array_keys($data['tables']));
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("The table {$table} is not empty; restore only puts data back into empty tables.");
            }
        }

        if (isset($data['tables']['passport_entries'])) {
            $data['tables']['passport_entries'] = self::numberPassports($data['tables']['passport_entries']);
        }

        $restored = DB::transaction(function () use ($tables, $data) {
            $counts = [];
            // passport_entries and medical_records point at each other (current_medical_id), so
            // no insert order satisfies both: check keys only once everything from the (one,
            // consistent) backup is back.
            Schema::disableForeignKeyConstraints();
            try {
                foreach ($tables as $table) {
                    foreach (array_chunk($data['tables'][$table], 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                    $counts[$table] = count($data['tables'][$table]);
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
            if (isset($data['cash_settings'])) {
                DB::table('cash_settings')->where('id', 1)->update($data['cash_settings']);
            }

            return $counts;
        });

        $files = 0;
        $prefix = 'phar://'.$archivePath.'/scans/';
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            if (! str_starts_with($file->getPathname(), $prefix)) {
                continue;
            }
            $relative = Str::after($file->getPathname(), $prefix);
            $this->disk()->put(self::SCAN_FOLDER.'/'.$relative, (string) file_get_contents($file->getPathname()));
            $files++;
        }

        return ['restored' => $restored, 'files' => $files];
    }

    /**
     * Write the backup and return its file name.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $groups
     */
    private function backup(array $tables, bool $withScans, bool $withCash, array $groups, User $user): string
    {
        $now = Carbon::now();
        $name = 'data-backup-'.$now->format('Y-m-d-His').'.tar.gz';
        $staging = storage_path('app/data-backup-staging-'.Str::random(8));
        // Built under a random name, then moved into place: PHP's Phar caches archives by
        // path, so reusing a path in the same process would read a stale copy.
        $tar = storage_path('app/data-backup-'.Str::random(12).'.tar');

        $this->disk()->makeDirectory(self::BACKUP_FOLDER);
        mkdir($staging, 0700, true);

        try {
            $data = ['tables' => []];
            $rows = 0;
            foreach ($tables as $table) {
                $data['tables'][$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                $rows += count($data['tables'][$table]);
            }
            if ($withCash) {
                $data['cash_settings'] = (array) DB::table('cash_settings')->where('id', 1)
                    ->first(['opening_balance', 'opening_date', 'updated_by']);
            }

            $files = 0;
            if ($withScans) {
                foreach ($this->disk()->allFiles(self::SCAN_FOLDER) as $path) {
                    $target = $staging.'/scans/'.Str::after($path, self::SCAN_FOLDER.'/');
                    @mkdir(dirname($target), 0700, true);
                    copy($this->disk()->path($path), $target);
                    $files++;
                }
            }

            $data['meta'] = [
                'created_at' => $now->toIso8601String(),
                'created_by' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'groups' => $groups,
                'rows' => $rows,
                'files' => $files,
            ];
            file_put_contents($staging.'/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            file_put_contents($staging.'/meta.json', json_encode($data['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            $archive = new PharData($tar);
            $archive->buildFromDirectory($staging);
            $archive->compress(\Phar::GZ);
            unset($archive);
            rename($tar.'.gz', $this->disk()->path(self::BACKUP_FOLDER.'/'.$name));
        } finally {
            $this->removeDirectory($staging);
            foreach ([$tar, $tar.'.gz'] as $leftover) {
                if (file_exists($leftover)) {
                    \Phar::unlinkArchive($leftover);
                }
            }
        }

        if (! $this->disk()->exists(self::BACKUP_FOLDER.'/'.$name)) {
            throw new RuntimeException('The backup could not be written, so nothing was deleted.');
        }

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $path): array
    {
        try {
            return $this->withArchive(
                $path,
                fn (PharData $archive) => json_decode((string) file_get_contents($archive['meta.json']->getPathname()), true) ?: [],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Open a backup through a private temporary copy: PHP's Phar caches archives by path for
     * the whole process, so opening the original twice could read an older file of that name.
     *
     * @template T
     *
     * @param  callable(PharData, string): T  $read  gets the archive and the path it was opened at
     * @return T
     */
    private function withArchive(string $path, callable $read): mixed
    {
        $copy = storage_path('app/data-backup-read-'.Str::random(12).'.tar.gz');
        copy($path, $copy);

        $archive = new PharData($copy);

        try {
            return $read($archive, $copy);
        } finally {
            // Release the archive first: Phar will not drop an archive that is still open.
            unset($archive);
            try {
                \Phar::unlinkArchive($copy);
            } catch (\Throwable) {
                // Already gone from Phar's cache; the plain delete below is enough.
            }
            if (file_exists($copy)) {
                unlink($copy);
            }
        }
    }

    /**
     * Delete every scan file, keeping the (empty) folder. Returns how many were deleted.
     */
    private function clearScans(): int
    {
        $files = $this->disk()->allFiles(self::SCAN_FOLDER);
        $this->disk()->delete($files);
        foreach ($this->disk()->directories(self::SCAN_FOLDER) as $directory) {
            $this->disk()->deleteDirectory($directory);
        }
        $this->disk()->makeDirectory(self::SCAN_FOLDER);

        return count($files);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(PassportScanService::DISK);
    }
}
