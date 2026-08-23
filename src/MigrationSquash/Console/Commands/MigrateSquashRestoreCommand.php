<?php

namespace MigrationSquash\Console\Commands;

use Illuminate\Console\Command;
use MigrationSquash\Archiving\MigrationArchiver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:squash:restore')]
class MigrateSquashRestoreCommand extends Command
{
    protected $signature = '
        migrate:squash:restore
                    {--archive= : Restore a specific archive folder instead of the newest}
                    {--list : List available archives and exit}
                    {--force : Overwrite files that already exist in the migration folder}
                    {--keep-squashed : Keep the generated squashed migrations instead of removing them}
    ';

    protected $description = 'Restore migrations from a squash archive using its manifest';

    public function handle(): int
    {
        $archiver = new MigrationArchiver;

        $archives = $archiver->listArchives();

        if (empty($archives)) {
            $this->error('❌ No archives found. Nothing to restore.');
            $this->line('   Looked in: '.$archiver->getArchiveRoot());

            return Command::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listArchives($archives);
        }

        $archivePath = $this->option('archive') ?: end($archives);

        if (! is_dir($archivePath)) {
            $this->error("❌ Archive folder not found: {$archivePath}");

            return Command::FAILURE;
        }

        $manifest = $archiver->readManifest($archivePath);

        if ($manifest === null) {
            $this->error("❌ No archive-manifest.json in {$archivePath}");
            $this->line('   Without a manifest this command cannot tell where each file belongs.');

            return Command::FAILURE;
        }

        $this->info('📦 Restoring from: '.$archivePath);
        $this->line('   Archived at: '.($manifest['archived_at'] ?? 'unknown'));
        $this->line('   Files in manifest: '.count($manifest['files'] ?? []));
        $this->newLine();

        $plan = $this->buildPlan($archivePath, $manifest);

        if (! empty($plan['missing'])) {
            $this->error('❌ '.count($plan['missing']).' file(s) listed in the manifest are missing from the archive:');

            foreach ($plan['missing'] as $filename) {
                $this->line("   • {$filename}");
            }

            return Command::FAILURE;
        }

        if (! empty($plan['corrupt'])) {
            $this->error('❌ '.count($plan['corrupt']).' file(s) do not match the hash recorded in the manifest:');

            foreach ($plan['corrupt'] as $filename) {
                $this->line("   • {$filename}");
            }

            $this->line('   Refusing to restore altered files. Inspect them by hand.');

            return Command::FAILURE;
        }

        if (! empty($plan['conflicts']) && ! $this->option('force')) {
            $this->error('❌ '.count($plan['conflicts']).' file(s) already exist at their original path:');

            foreach ($plan['conflicts'] as $path) {
                $this->line('   • '.basename($path));
            }

            $this->line('   Re-run with --force to overwrite them.');

            return Command::FAILURE;
        }

        $squashed = $this->option('keep-squashed') ? [] : $this->findSquashedMigrations($manifest);

        $this->line('   Files to restore: '.count($plan['restore']));

        if (! empty($squashed)) {
            $this->line('   Squashed migrations to remove: '.count($squashed));
        }

        if (! $this->confirm('Continue?', true)) {
            $this->info('Cancelled. Nothing was changed.');

            return Command::SUCCESS;
        }

        foreach ($plan['restore'] as $source => $destination) {
            if (! @copy($source, $destination)) {
                $this->error("❌ Failed to restore {$destination}");
                $this->error('   Restore stopped part-way. The archive is untouched at '.$archivePath);

                return Command::FAILURE;
            }
        }

        foreach ($squashed as $file) {
            @unlink($file);
        }

        $this->newLine();
        $this->info('✅ Restored '.count($plan['restore']).' migration(s).');

        if (! empty($squashed)) {
            $this->info('✅ Removed '.count($squashed).' squashed migration(s).');
        }

        $this->line('The archive was left in place at '.$archivePath);

        return Command::SUCCESS;
    }

    /**
     * Print the available archives, newest last.
     *
     * @param  array<int, string>  $archives
     */
    protected function listArchives(array $archives): int
    {
        $archiver = new MigrationArchiver;

        $this->info('📦 Available archives:');

        foreach ($archives as $path) {
            $manifest = $archiver->readManifest($path);
            $count = $manifest !== null ? count($manifest['files'] ?? []) : '?';
            $when = $manifest['archived_at'] ?? 'unknown';

            $this->line('   • '.basename($path)."  ({$count} files, archived {$when})");

            if ($manifest === null) {
                $this->line('     ⚠️ no manifest, cannot be restored by this command');
            }
        }

        $this->newLine();
        $this->line('Restore the newest with: php artisan migrate:squash:restore');
        $this->line('Or a specific one with:  php artisan migrate:squash:restore --archive=<path>');

        return Command::SUCCESS;
    }

    /**
     * Work out what to restore, and what is wrong before touching anything.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{restore: array<string, string>, missing: array<int, string>, corrupt: array<int, string>, conflicts: array<int, string>}
     */
    protected function buildPlan(string $archivePath, array $manifest): array
    {
        $restore = [];
        $missing = [];
        $corrupt = [];
        $conflicts = [];

        foreach ($manifest['files'] ?? [] as $entry) {
            $filename = $entry['filename'] ?? null;

            if ($filename === null) {
                continue;
            }

            $source = $archivePath.'/'.$filename;

            if (! file_exists($source)) {
                $missing[] = $filename;

                continue;
            }

            if (isset($entry['hash']) && md5_file($source) !== $entry['hash']) {
                $corrupt[] = $filename;

                continue;
            }

            $destination = $entry['original_path'] ?? database_path('migrations/'.$filename);

            if (file_exists($destination)) {
                $conflicts[] = $destination;
            }

            $restore[$source] = $destination;
        }

        return compact('restore', 'missing', 'corrupt', 'conflicts');
    }

    /**
     * Find squashed migrations sitting in the migration folder.
     *
     * Anything in the folder that the manifest does NOT know about, and that
     * was modified at or after the archive was written, is a file this package
     * generated during that squash.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    protected function findSquashedMigrations(array $manifest): array
    {
        $sourcePath = $manifest['source_path'] ?? database_path('migrations');

        if (! is_dir($sourcePath)) {
            return [];
        }

        $known = array_column($manifest['files'] ?? [], 'filename');

        $archivedAt = isset($manifest['archived_at'])
            ? strtotime($manifest['archived_at'])
            : null;

        $squashed = [];

        foreach (glob($sourcePath.'/*.php') ?: [] as $file) {
            if (in_array(basename($file), $known, true)) {
                continue;
            }

            // Leave anything written before the squash alone; it belongs to
            // the user, not to us.
            if ($archivedAt !== null && filemtime($file) < $archivedAt) {
                continue;
            }

            $squashed[] = $file;
        }

        return $squashed;
    }
}
