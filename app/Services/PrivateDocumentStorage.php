<?php

namespace App\Services;

use App\Models\PrivateFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateDocumentStorage
{
    public function storeKyc(UploadedFile $upload, User $owner): PrivateFile
    {
        $key = $owner->getKey().'/'.now()->format('Y/m').'/'.Str::uuid().'.enc';
        $contents = $upload->getContent();
        $encrypted = Crypt::encryptString($contents);

        Storage::disk('kyc')->put($key, $encrypted);

        return PrivateFile::create([
            'owner_user_id' => $owner->getKey(),
            'disk' => 'kyc',
            'storage_key' => $key,
            'original_name' => Str::limit($upload->getClientOriginalName(), 240, ''),
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'is_encrypted' => true,
            'expires_at' => now()->addDays((int) config('ads-platform.kyc_retention_days', 1825)),
        ]);
    }

    public function read(PrivateFile $file): string
    {
        $payload = Storage::disk($file->disk)->get($file->storage_key);

        return $file->is_encrypted ? Crypt::decryptString($payload) : $payload;
    }

    public function delete(PrivateFile $file): void
    {
        Storage::disk($file->disk)->delete($file->storage_key);
        $file->delete();
    }
}
