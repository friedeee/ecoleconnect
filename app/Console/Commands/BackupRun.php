<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('backup:run {--keep=14 : Nombre de jours de sauvegardes à conserver}')]
#[Description('Sauvegarde la base de données et les fichiers déposés dans storage/app/backups')]
class BackupRun extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Y-m-d_His');
        $database = config('database.connections.pgsql');
        $dumpPath = "{$directory}/base-{$stamp}.dump";

        // Custom format: compressed, and restorable table by table with pg_restore.
        $dump = Process::timeout(600)
            ->env(['PGPASSWORD' => (string) $database['password']])
            ->run([
                'pg_dump', '--format=custom', '--no-owner',
                '--host='.$database['host'], '--port='.$database['port'],
                '--username='.$database['username'], '--file='.$dumpPath,
                $database['database'],
            ]);

        if ($dump->failed()) {
            $this->error('Échec de la sauvegarde de la base : '.trim($dump->errorOutput()));

            return self::FAILURE;
        }

        $this->info('Base sauvegardée : '.$dumpPath);

        // Worksheets and other uploaded files are not in the database.
        if (File::isDirectory(storage_path('app/private'))) {
            $filesPath = "{$directory}/fichiers-{$stamp}.tar.gz";
            $archive = Process::timeout(600)->run(['tar', '-czf', $filesPath, '-C', storage_path('app'), 'private']);

            if ($archive->failed()) {
                $this->error('Échec de l\'archive des fichiers : '.trim($archive->errorOutput()));

                return self::FAILURE;
            }

            $this->info('Fichiers archivés : '.$filesPath);
        }

        $this->prune($directory, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    /**
     * Deletes backups older than the given number of days.
     */
    protected function prune(string $directory, int $days): void
    {
        $limit = now()->subDays($days)->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $limit && preg_match('/^(base|fichiers)-.*\.(dump|tar\.gz)$/', $file->getFilename())) {
                File::delete($file->getPathname());
                $this->line('Ancienne sauvegarde supprimée : '.$file->getFilename());
            }
        }
    }
}
