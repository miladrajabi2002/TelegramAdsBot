<?php

namespace App\Console\Commands;

use App\Models\SuggestedChannel;
use App\Services\Telegram\ChannelAvatarFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot command to backfill missing/empty avatar_url values on the
 * suggested_channels table by scraping t.me pages.
 *
 * Usage:
 *   php artisan channels:backfill-avatars              # only rows with empty avatar_url
 *   php artisan channels:backfill-avatars --force       # re-fetch ALL rows (overwrite existing)
 *   php artisan channels:backfill-avatars --limit=50    # cap at 50 rows
 *   php artisan channels:backfill-avatars --sleep=1     # sleep 1 sec between requests (be nice to t.me)
 *
 * The command is idempotent: running it twice won't double-fetch rows
 * that already have an avatar (unless --force is passed).
 */
class BackfillChannelAvatars extends Command
{
    protected $signature = 'channels:backfill-avatars
                            {--force : Re-fetch avatars for ALL channels, even those with a non-empty avatar_url}
                            {--limit= : Maximum number of channels to process (default: no limit)}
                            {--sleep=0 : Seconds to sleep between HTTP requests (default: 0)}';

    protected $description = 'Backfill missing avatar_url on suggested_channels by scraping t.me pages.';

    public function handle(ChannelAvatarFetcher $fetcher): int
    {
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $sleep = (float) $this->option('sleep');

        $query = SuggestedChannel::query()->orderBy('id');
        if (!$force) {
            // Only fetch rows where avatar_url is null OR empty string.
            $query->where(function ($q) {
                $q->whereNull('avatar_url')->orWhere('avatar_url', '');
            });
        }
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            $this->info('No channels need an avatar backfill. All rows already have an avatar_url.');
            return self::SUCCESS;
        }

        $this->info("Starting avatar backfill for {$total} channel(s)." . ($force ? ' (--force: overwriting existing avatars)' : ''));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $fetched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            $username = $channel->username;
            if (!$username) {
                $bar->advance();
                $skipped++;
                continue;
            }

            try {
                $avatarUrl = $fetcher->fetchByUsername($username);
                if ($avatarUrl) {
                    $channel->avatar_url = $avatarUrl;
                    $channel->save();
                    $fetched++;
                    Log::info('BackfillChannelAvatars: updated avatar', [
                        'channel_id' => $channel->id,
                        'username'   => $username,
                        'avatar_url' => $avatarUrl,
                    ]);
                } else {
                    $failed++;
                    Log::warning('BackfillChannelAvatars: no avatar found on t.me page', [
                        'channel_id' => $channel->id,
                        'username'   => $username,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('BackfillChannelAvatars: exception', [
                    'channel_id' => $channel->id,
                    'username'   => $username,
                    'error'      => $e->getMessage(),
                ]);
            }

            $bar->advance();

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Fetched: {$fetched}, skipped: {$skipped}, failed: {$failed} (out of {$total}).");

        return self::SUCCESS;
    }
}
