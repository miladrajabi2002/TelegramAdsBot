<?php

namespace App\Services\Telegram;

use Illuminate\Validation\UnauthorizedException;

class TelegramInitDataValidator
{
    /**
     * Validate Telegram Mini App initData and return its decoded fields.
     *
     * @return array<string, mixed>
     */
    public function validate(string $initData): array
    {
        parse_str($initData, $data);

        $receivedHash = $data['hash'] ?? null;
        unset($data['hash'], $data['signature']);

        if (! is_string($receivedHash) || $receivedHash === '') {
            throw new UnauthorizedException('Telegram signature is missing.');
        }

        $botToken = (string) config('services.telegram.bot_token');
        if ($botToken === '') {
            throw new UnauthorizedException('Telegram bot is not configured.');
        }

        ksort($data, SORT_STRING);
        $checkString = collect($data)
            ->map(fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode("\n");

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $checkString, $secret);

        if (! hash_equals($calculatedHash, $receivedHash)) {
            throw new UnauthorizedException('Telegram signature is invalid.');
        }

        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $ttl = max(60, (int) config('services.telegram.init_data_ttl', 900));

        if (! $authDate || now()->timestamp - $authDate > $ttl || $authDate > now()->timestamp + 30) {
            throw new UnauthorizedException('Telegram session has expired.');
        }

        foreach (['user', 'receiver', 'chat'] as $jsonField) {
            if (isset($data[$jsonField]) && is_string($data[$jsonField])) {
                $data[$jsonField] = json_decode($data[$jsonField], true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}
