<?php

namespace App\Services;

use App\Models\ManagedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileVaultService
{
    private const DISK = 'local';
    private const ROOT = 'managed-files';

    public function storeUploadedFile(
        UploadedFile $file,
        ?int $userId,
        string $sourceLabel = 'Upload'
    ): ?ManagedFile {
        try {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $storedName = (string) Str::uuid() . ($extension ? '.' . $extension : '');
            $path = $file->storeAs(self::ROOT . '/uploads', $storedName, self::DISK);

            if (! $path) {
                throw new \RuntimeException('Could not store uploaded file.');
            }

            return $this->createRecord(
                $file->getClientOriginalName(),
                $path,
                $file->getMimeType() ?: 'application/octet-stream',
                (int) ($file->getSize() ?: 0),
                $this->sha256(Storage::disk(self::DISK)->path($path)),
                'upload',
                $sourceLabel,
                $userId
            );
        } catch (\Throwable $e) {
            Log::warning('FileVault upload failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function storeGeneratedContent(
        string $originalName,
        string $content,
        string $mimeType,
        ?int $userId,
        string $sourceLabel = 'Generated'
    ): ?ManagedFile {
        try {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $storedName = (string) Str::uuid() . ($extension ? '.' . strtolower($extension) : '');
            $path = self::ROOT . '/generated/' . $storedName;
            Storage::disk(self::DISK)->put($path, $content);

            return $this->createRecord(
                $originalName,
                $path,
                $mimeType,
                strlen($content),
                hash('sha256', $content),
                'generated',
                $sourceLabel,
                $userId
            );
        } catch (\Throwable $e) {
            Log::warning('FileVault generated file failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function registerExistingPrivateFile(
        string $absolutePath,
        string $originalName,
        string $mimeType,
        string $sourceLabel,
        ?int $userId = null
    ): ?ManagedFile {
        try {
            if (! is_file($absolutePath)) {
                return null;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                return null;
            }

            return $this->storeGeneratedContent($originalName, $contents, $mimeType, $userId, $sourceLabel);
        } catch (\Throwable $e) {
            Log::warning('FileVault existing file registration failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function recordDownload(ManagedFile $file, ?int $userId): void
    {
        app(AuditLogService::class)->record(
            'download',
            'Downloaded managed file',
            request(),
            [
                'managed_file_id' => $file->id,
                'filename' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'sha256' => $file->sha256,
                'file_kind' => $file->file_kind,
                'source_label' => $file->source_label,
                'uploaded_by_user_id' => $file->uploaded_by_user_id,
            ],
            'ManagedFile',
            $file->id,
            $userId,
            session('user_name'),
            session('user_role'),
            200
        );
    }

    public function path(ManagedFile $file): string
    {
        $path = ltrim((string) $file->storage_path, '/');
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, self::ROOT . '/')) {
            abort(404, 'Invalid managed file.');
        }

        $absolute = Storage::disk($file->disk ?: self::DISK)->path($path);
        if (! is_file($absolute)) {
            abort(404, 'File is no longer available.');
        }

        return $absolute;
    }

    private function createRecord(
        string $originalName,
        string $path,
        string $mimeType,
        int $size,
        string $sha256,
        string $kind,
        string $sourceLabel,
        ?int $userId
    ): ManagedFile {
        return ManagedFile::create([
            'original_name' => substr($originalName, 0, 255),
            'storage_path' => $path,
            'disk' => self::DISK,
            'mime_type' => substr($mimeType, 0, 150),
            'size_bytes' => $size,
            'sha256' => $sha256,
            'file_kind' => $kind,
            'source_label' => substr($sourceLabel, 0, 100),
            'uploaded_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    private function sha256(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        return $hash ?: '';
    }
}
