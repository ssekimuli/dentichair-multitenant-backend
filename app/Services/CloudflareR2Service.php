<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CloudflareR2Service
{
    /**
     * Upload a file to Cloudflare R2 and return the public URL
     *
     * @param UploadedFile $file The file to upload
     * @param string $folder The folder to store the file in (e.g., 'avatars', 'leads')
     * @param string|null $customFilename Optional custom filename without extension (e.g., '1_John_Doe_facephoto')
     * @return string The public URL of the uploaded file
     */
    public function upload(UploadedFile $file, string $folder = 'uploads', ?string $customFilename = null): string
    {
        $ext = $file->getClientOriginalExtension();
        $filename = $customFilename ?? Str::uuid();
        $name = $folder . '/' . $filename . '.' . $ext;

        Storage::disk('r2')->put($name, $file->getContent(), 'public');

        $baseUrl = config('filesystems.disks.r2.url');
        return rtrim((string) $baseUrl, '/') . '/' . $name;
    }

    /**
     * Delete a file from Cloudflare R2 using its public URL
     *
     * @param string|null $url The public URL of the file
     * @return void
     */
    public function delete(?string $url): void
    {
        if (!$url) {
            return;
        }

        $base = rtrim((string) config('filesystems.disks.r2.url'), '/') . '/';

        if ($base !== '/' && str_starts_with($url, $base)) {
            $key = substr($url, strlen($base));
            Storage::disk('r2')->delete($key);
        }
    }
}
