<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserWhatsAppAccount extends Model
{
    protected $table = 'user_whatsapp_accounts';

    protected $fillable = [
        'user_id',
        'account_type',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return $this->account_type === 'business' ? 'Business WhatsApp' : 'Personal WhatsApp';
    }
}
