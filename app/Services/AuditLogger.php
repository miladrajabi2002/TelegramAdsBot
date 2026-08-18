<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $actor = null,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'before_redacted' => $this->redact($before),
            'after_redacted' => $this->redact($after),
            'reason' => $reason,
            'correlation_id' => (string) Str::uuid(),
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 1000, ''),
        ]);
    }

    private function redact(array $data): array
    {
        $blocked = ['password', 'token', 'secret', 'national_id', 'pan', 'card_number', 'contents'];

        return collect($data)->mapWithKeys(function (mixed $value, string|int $key) use ($blocked): array {
            $name = strtolower((string) $key);
            $sensitive = collect($blocked)->contains(fn (string $needle): bool => str_contains($name, $needle));

            return [$key => $sensitive ? '[REDACTED]' : $value];
        })->all();
    }
}
