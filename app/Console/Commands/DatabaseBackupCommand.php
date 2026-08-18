<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * ЭТАП 21.3 — production had no database backup story at all until this
 * command. Dumps the whole database with pg_dump's compressed custom format
 * (`-Fc`, restorable with `pg_restore`, not a plain-text .sql), stores it on
 * the `local` disk (storage/app — not web-accessible), and prunes anything
 * past the retention window on every run so this never grows unbounded.
 */
class DatabaseBackupCommand extends Command
{
    private const RETENTION_DAYS = 14;
    private const BACKUP_DIR = 'backups';

    protected $signature = 'db:backup';

    protected $description = 'Dump the database to storage/app/backups and prune backups older than the retention window.';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (($connection['driver'] ?? null) !== 'pgsql') {
            $this->error('db:backup only supports the pgsql driver today; configured driver is '.($connection['driver'] ?? 'unknown').'.');

            return self::FAILURE;
        }

        Storage::disk('local')->makeDirectory(self::BACKUP_DIR);
        $filename = self::BACKUP_DIR.'/ai_crm_'.now()->format('Y-m-d_His').'.dump';
        $absolutePath = Storage::disk('local')->path($filename);

        $result = Process::timeout(600)
            ->env(['PGPASSWORD' => (string) $connection['password']])
            ->run([
                'pg_dump',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--format=custom',
                '--file='.$absolutePath,
                $connection['database'],
            ]);

        if (! $result->successful()) {
            $this->error('pg_dump failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $sizeMb = round(filesize($absolutePath) / 1024 / 1024, 1);
        $this->info("Backup written: {$filename} ({$sizeMb} MB).");

        $pruned = $this->pruneOldBackups();
        if ($pruned > 0) {
            $this->line("Pruned {$pruned} backup(s) older than ".self::RETENTION_DAYS.' days.');
        }

        return self::SUCCESS;
    }

    private function pruneOldBackups(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS)->timestamp;
        $pruned = 0;

        foreach (Storage::disk('local')->files(self::BACKUP_DIR) as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
                $pruned++;
            }
        }

        return $pruned;
    }
}
