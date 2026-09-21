<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_user_id', 'actor_name', 'actor_role', 'event_category', 'action',
        'target_type', 'target_id', 'route_name', 'method', 'path', 'status_code',
        'ip_address', 'user_agent', 'details', 'created_at', 'previous_hash',
        'hash', 'hash_version',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'actor_user_id' => 'integer', 'target_id' => 'integer', 'status_code' => 'integer',
        'details' => 'array', 'created_at' => 'datetime', 'hash_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Audit logs are immutable.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Audit logs are immutable.');
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Canonical payload used for the tamper-evident chain. */
    public function hashPayload(): string
    {
        $details = $this->details;
        if (is_array($details)) {
            $details = $this->canonicalize($details);
        }

        return json_encode([
            'id' => (int) $this->id,
            'actor_user_id' => $this->actor_user_id === null ? null : (int) $this->actor_user_id,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'event_category' => $this->event_category,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id === null ? null : (int) $this->target_id,
            'route_name' => $this->route_name,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->status_code === null ? null : (int) $this->status_code,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'details' => $details,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s.u'),
            'previous_hash' => $this->previous_hash,
            'hash_version' => (int) ($this->hash_version ?: 1),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function calculateHash(): string
    {
        return hash('sha256', $this->hashPayload());
    }

    public function hashMatches(): bool
    {
        return filled($this->hash) && hash_equals($this->hash, $this->calculateHash());
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
