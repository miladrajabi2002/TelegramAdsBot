<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a channel's profile-photo URL by scraping the public t.me page.
 *
 * Telegram's Bot API getChat() returns a `photo.big_file_id` that requires
 * a second getFile() call AND yields a URL that expires in ~1 hour. That
 * makes it useless for long-term display in admin panels or campaign
 * wizards — the avatar disappears after an hour.
 *
 * The public https://t.me/<username> page, on the other hand, contains a
 * stable CDN URL in an <img class="tgme_page_photo_image" src="..."> tag.
 * That URL is hosted on cdn4.telesco.pe / cdn5.telesco.pe and does NOT
 * expire. We parse it out of the HTML with a regex (the page is small and
 * the class name is stable across all Telegram channel pages).
 *
 * Used by:
 * - CatalogController::storeChannel() / update() / lookupChannel() — when
 *   an admin adds or refreshes a suggested channel.
 * - CampaignController::searchChannel() — when a user searches for a
 *   channel that's not in the catalogue yet.
 * - BackfillChannelAvatars command — one-shot refill for existing rows.
 */
class ChannelAvatarFetcher
{
    /**
     * Fetch the stable CDN avatar URL for a channel by scraping t.me.
     *
     * @param  string  $username  Channel username WITHOUT the leading @.
     * @return string|null  The CDN URL (e.g. https://cdn4.telesco.pe/file/...jpg), or null if not found / on error.
     */
    public function fetchByUsername(string $username): ?string
    {
        $username = ltrim(trim($username), '@');
        if ($username === '' || !preg_match('/^[A-Za-z0-9_]{4,64}$/', $username)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
                ->timeout(15)
                ->connectTimeout(8)
                ->retry(2, 500)
                ->get("https://t.me/{$username}");

            if (!$response->successful()) {
                Log::debug('ChannelAvatarFetcher: t.me request failed', [
                    'username' => $username,
                    'status'   => $response->status(),
                ]);
                return null;
            }

            $html = $response->body();
            if ($html === '') {
                return null;
            }

            return $this->extractAvatarFromHtml($html);
        } catch (\Throwable $e) {
            Log::warning('ChannelAvatarFetcher: exception while fetching', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract the tgme_page_photo_image src from the t.me HTML.
     *
     * The HTML contains one of these patterns (in order of preference):
     *   <img class="tgme_page_photo_image" src="https://cdn4.telesco.pe/file/...">
     *   <img class="tgme_page_photo_image" srcset="... 1x, ... 2x">
     *
     * We try `src` first (highest resolution single image), then fall back
     * to `srcset` (parse the first URL out of the comma-separated list).
     */
    public function extractAvatarFromHtml(string $html): ?string
    {
        // Pattern 1: <img class="tgme_page_photo_image" src="URL">
        // The class attribute may have additional classes; the src may use
        // single or double quotes. We capture the URL greedily up to the
        // closing quote.
        if (preg_match(
            '/<img[^>]*class="[^"]*tgme_page_photo_image[^"]*"[^>]*src="([^"]+)"/i',
            $html,
            $matches
        )) {
            return $this->normalizeUrl($matches[1]);
        }

        // Pattern 1b: same img but attributes in reverse order (src before class)
        if (preg_match(
            '/<img[^>]*src="([^"]+)"[^>]*class="[^"]*tgme_page_photo_image[^"]*"/i',
            $html,
            $matches
        )) {
            return $this->normalizeUrl($matches[1]);
        }

        // Pattern 2: <img class="tgme_page_photo_image" srcset="URL 1x, URL 2x">
        // Take the FIRST URL in the srcset (typically the 1x variant).
        if (preg_match(
            '/<img[^>]*class="[^"]*tgme_page_photo_image[^"]*"[^>]*srcset="([^"]+)"/i',
            $html,
            $matches
        )) {
            $srcset = $matches[1];
            // srcset format: "url1 1x, url2 2x" — take the first URL.
            $first = trim(explode(',', $srcset)[0]);
            $url = trim(preg_replace('/\s+\d+x\s*$/', '', $first));
            return $this->normalizeUrl($url);
        }

        return null;
    }

    /**
     * Normalize a scraped URL: trim whitespace, ensure it's https, and
     * reject obviously-bogus values (data: URIs, javascript:, etc.).
     */
    private function normalizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        // Only accept http(s) URLs from the Telegram CDN.
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        // Force https — the CDN supports it and mixed-content on https
        // pages would break the avatar display.
        $url = preg_replace('~^http://~i', 'https://', $url);
        return $url;
    }
}
