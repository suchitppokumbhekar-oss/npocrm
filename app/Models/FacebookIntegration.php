<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookIntegration extends Model
{
    protected $table = 'facebook_integrations';

    protected $fillable = [
        'user_id', 'fb_user_id', 'fb_user_name',
        'user_access_token', 'token_expires_at', 'pages_json',
        'selected_page_id', 'selected_page_name', 'selected_page_token',
        'status', 'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pages(): array
    {
        return $this->pages_json ? (json_decode($this->pages_json, true) ?: []) : [];
    }

    public function isExpired(): bool
    {
        if (! $this->token_expires_at) return false;
        return $this->token_expires_at->isPast();
    }

    public function hasSelectedPage(): bool
    {
        return ! empty($this->selected_page_id) && ! empty($this->selected_page_token);
    }
}