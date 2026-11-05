<?php

namespace App\Console\Commands;

use App\Services\DataReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Puts a "delete all data" backup back (Settings → Danger zone). The tables it holds must be
 * empty, so nothing is ever overwritten.
 */
#[Signature('app:restore-data-backup
    {file : Backup name (data-backup-….tar.gz in storage/app/private/data-backups) or a full path}
    {--force : Skip the confirmation}')]
#[Description('Restore the data saved by a "delete all data" reset')]
class RestoreDataBackup extends Command
{
    public function handle(DataReset $reset): int
    {
        $file = (string) $this->argument('file');
        $path = $reset->backupPath($file) ?? (is_file($file) ? $file : null);

        if ($path === null) {
            $this->error("Backup not found: {$file}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Restore {$file}? Its tables must be empty.")) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $reset->restore($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->error('Nothing was restored.');

            return self::FAILURE;
        }

        foreach ($result['restored'] as $table => $rows) {
            $this->line("Restored {$rows} row(s) into {$table}.");
        }
        $this->line("Restored {$result['files']} scan file(s).");
        $this->info('Done.');

        return self::SUCCESS;
    }
}
