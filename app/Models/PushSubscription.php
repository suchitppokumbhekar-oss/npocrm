<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
        'last_notification_id',
        'last_used_at',
        'last_error_at',
    ];

    protected $casts = [
        'last_notification_id' => 'integer',
        'last_used_at' => 'datetime',
        'last_error_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
