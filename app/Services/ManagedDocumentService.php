<?php

namespace App\Services;

use App\Models\ManagedFile;
use App\Models\ManagedFileEvent;
use App\Models\ManagedFileLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ManagedDocumentService
{
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'video/mp4',
        'video/quicktime',
    ];

    public const MAX_FILE_SIZE = 25 * 1024 * 1024;

    public function store(
        UploadedFile $upload,
        array $attributes,
        string $entityType,
        int $entityId,
        ?int $userId
    ): ManagedFile {
        if (! $upload->isValid()) {
            throw new RuntimeException('The uploaded file is not valid.');
        }

        $mime = (string) $upload->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('This file type is not allowed.');
        }

        if ($upload->getSize() > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds the 25 MB upload limit.');
        }

        $disk = 'local';
        $extension = strtolower((string) $upload->guessExtension());
        $storedName = (string) Str::uuid() . ($extension !== '' ? '.' . $extension : '');
        $directory = 'managed-documents/' . now()->format('Y/m');
        $path = $upload->storeAs($directory, $storedName, $disk);

        if (! $path) {
            throw new RuntimeException('Unable to store uploaded file.');
        }

        try {
            return DB::transaction(function () use (
                $upload,
                $attributes,
                $entityType,
                $entityId,
                $userId,
                $disk,
                $path,
                $mime
            ) {
                $file = ManagedFile::create([
                    'original_name' => $upload->getClientOriginalName(),
                    'title' => $attributes['title'] ?? $upload->getClientOriginalName(),
                    'description' => $attributes['description'] ?? null,
                    'storage_path' => $path,
                    'disk' => $disk,
                    'mime_type' => $mime,
                    'size_bytes' => $upload->getSize(),
                    'sha256' => hash_file('sha256', $upload->getRealPath()),
                    'file_kind' => $attributes['file_kind'] ?? $this->fileKind($mime),
                    'document_category' => $attributes['document_category'] ?? 'general',
                    'visibility' => $attributes['visibility'] ?? 'internal',
                    'customer_shareable' => (bool) ($attributes['customer_shareable'] ?? false),
                    'share_approved' => false,
                    'version_number' => (int) ($attributes['version_number'] ?? 1),
                    'replaces_file_id' => $attributes['replaces_file_id'] ?? null,
                    'valid_from' => $attributes['valid_from'] ?? null,
                    'valid_until' => $attributes['valid_until'] ?? null,
                    'source_label' => $attributes['source_label'] ?? null,
                    'uploaded_by_user_id' => $userId,
                    'created_at' => now(),
                ]);

                ManagedFileLink::create([
                    'managed_file_id' => $file->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'context_type' => $attributes['context_type'] ?? null,
                    'context_id' => $attributes['context_id'] ?? null,
                    'workflow_stage' => $attributes['workflow_stage'] ?? null,
                    'relationship' => $attributes['relationship'] ?? 'attachment',
                    'linked_by_user_id' => $userId,
                    'created_at' => now(),
                ]);

                $this->recordEvent($file, 'uploaded', $userId, null, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'category' => $file->document_category,
                ]);

                return $file->fresh(['links', 'uploader']);
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    public function retireReplacedVersion(ManagedFile $file, ManagedFile $replacement, int $userId): ManagedFile
    {
        $file->forceFill([
            'valid_until' => now(),
            'share_approved' => false,
        ])->save();

        $this->recordEvent($file, 'replaced', $userId, null, [
            'replacement_file_id' => $replacement->id,
            'replacement_version' => $replacement->version_number,
        ]);

        $this->recordEvent($replacement, 'replacement_created', $userId, null, [
            'replaces_file_id' => $file->id,
            'previous_version' => $file->version_number,
        ]);

        return $file->fresh();
    }

    public function remove(ManagedFile $file, int $userId, ?string $reason = null): ManagedFile
    {
        if ($file->removed_at) {
            return $file;
        }

        $file->forceFill([
            'removed_at' => now(),
            'removed_by_user_id' => $userId,
            'removal_reason' => $reason,
            'share_approved' => false,
        ])->save();

        $this->recordEvent($file, 'removed', $userId, null, [
            'reason' => $reason,
        ]);

        return $file->fresh();
    }

    public function recordEvent(
        ManagedFile $file,
        string $eventType,
        ?int $userId,
        ?string $channel = null,
        array $details = []
    ): ManagedFileEvent {
        return ManagedFileEvent::create([
            'managed_file_id' => $file->id,
            'event_type' => $eventType,
            'user_id' => $userId,
            'channel' => $channel,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    private function fileKind(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        return 'document';
    }
}
