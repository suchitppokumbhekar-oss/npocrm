<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFile extends Model
{
    public $timestamps = false;

    protected $table = 'managed_files';

    protected $fillable = [
        'original_name',
        'title',
        'description',
        'storage_path',
        'disk',
        'mime_type',
        'size_bytes',
        'sha256',
        'file_kind',
        'document_category',
        'visibility',
        'customer_shareable',
        'share_approved',
        'share_approved_by_user_id',
        'share_approved_at',
        'version_number',
        'replaces_file_id',
        'valid_from',
        'valid_until',
        'removed_at',
        'removed_by_user_id',
        'removal_reason',
        'source_label',
        'uploaded_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'customer_shareable' => 'boolean',
        'share_approved' => 'boolean',
        'version_number' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'share_approved_by_user_id' => 'integer',
        'replaces_file_id' => 'integer',
        'removed_by_user_id' => 'integer',
        'share_approved_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'removed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function shareApprover()
    {
        return $this->belongsTo(User::class, 'share_approved_by_user_id');
    }

    public function removedBy()
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function links()
    {
        return $this->hasMany(ManagedFileLink::class, 'managed_file_id');
    }

    public function events()
    {
        return $this->hasMany(ManagedFileEvent::class, 'managed_file_id');
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_file_id');
    }

    public function replacements()
    {
        return $this->hasMany(self::class, 'replaces_file_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->whereNull('removed_at')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    public function scopeApprovedForSharing($query)
    {
        return $query
            ->active()
            ->where('customer_shareable', true)
            ->where('share_approved', true);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isApprovedForSharing(): bool
    {
        return ! $this->isRemoved()
            && $this->customer_shareable
            && $this->share_approved
            && (! $this->valid_from || $this->valid_from->lessThanOrEqualTo(now()))
            && (! $this->valid_until || $this->valid_until->greaterThanOrEqualTo(now()));
    }
}
