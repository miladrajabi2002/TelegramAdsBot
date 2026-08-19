<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Wipe ALL platform data and re-seed a fresh install.
 *
 * This command is the nuclear option for staging / dev environments.
 * It drops every platform table (created by the migrations) and
 * re-runs migrations + seeders. Use it when you want a clean slate:
 *
 *   php artisan db:reset
 *   php artisan db:reset --force     # skip the confirmation prompt
 *   php artisan db:reset --no-seed   # skip the seeders
 *
 * The --force flag skips the "are you sure?" prompt so the command
 * can be run from a deployment script or a Makefile.
 *
 * The command is blocked in production unless --force is also passed.
 * This prevents accidental data loss on a live server.
 */
class DbResetCommand extends Command
{
    protected $signature = 'db:reset
                            {--force : Skip the confirmation prompt and allow running in production}
                            {--seed : Run the seeders after resetting (default: yes)}
                            {--no-seed : Skip the seeders after resetting}';

    protected $description = '⚠ Drop ALL platform tables, re-run migrations, and re-seed. This destroys every order, user, transaction, KYC record and ledger entry.';

    public function handle(): int
    {
        $env = (string) config('app.env', 'production');
        $isProd = $env === 'production';
        $force = (bool) $this->option('force');

        if ($isProd && ! $force) {
            $this->error('Refusing to run in production. Pass --force to override (NOT RECOMMENDED).');

            return self::FAILURE;
        }

        // Manual confirmation prompt — Laravel's built-in confirmToProceed
        // has an unstable signature across versions (the `force:` named
        // argument breaks on Laravel 11+), so we do the same check inline.
        if (! $force) {
            $confirmed = $this->confirm(
                'This will PERMANENTLY DELETE all data in the database. Continue?',
                false,
            );
            if (! $confirmed) {
                $this->info('Aborted. No changes were made.');

                return self::SUCCESS;
            }
        }

        $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->warn('  ⚠  DATABASE RESET — every table will be dropped.');
        $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Step 1: wipe stored files (avatars etc.) that live under
        // storage/app — they reference rows we're about to delete.
        $this->info('Step 1/4 — clearing local file storage…');
        $this->clearStorageApp();

        // Step 2: drop all tables and re-run migrations.
        $this->info('Step 2/4 — dropping tables and re-running migrations…');
        $this->call('migrate:fresh', ['--force' => true]);

        // Step 3: seed (unless --no-seed was passed).
        $shouldSeed = $this->option('no-seed') ? false : (bool) $this->option('seed', true);
        if ($shouldSeed) {
            $this->info('Step 3/4 — running seeders…');
            $this->call('db:seed', ['--force' => true]);
        } else {
            $this->info('Step 3/4 — skipping seeders (--no-seed).');
        }

        // Step 4: clear framework caches so old views/config don't leak.
        $this->info('Step 4/4 — clearing caches…');
        $this->callSilent('view:clear');
        $this->callSilent('config:clear');
        $this->callSilent('cache:clear');

        $this->newLine();
        $this->info('✓ Database reset complete.');
        $this->info('  Env:        '.$env);
        $this->info('  Migrated:   yes');
        $this->info('  Seeded:     '.($shouldSeed ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    /**
     * Clear user-uploaded files (KYC documents, avatars, etc.) under
     * storage/app — but never touch framework subdirs (framework,
     * logs, debugging) which live alongside.
     */
    private function clearStorageApp(): void
    {
        $basePath = storage_path('app');
        if (! is_dir($basePath)) {
            return;
        }
        $protected = ['framework', 'logs', 'testing', 'debugging'];
        foreach (scandir($basePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, $protected, true)) {
                continue;
            }
            $fullPath = $basePath.'/'.$entry;
            if (is_dir($fullPath)) {
                File::deleteDirectory($fullPath);
            } else {
                File::delete($fullPath);
            }
        }
    }
}
