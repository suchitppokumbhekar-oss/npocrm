<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'name', 'phone', 'email',
        'first_seen_at', 'last_seen_at',
        'total_enquiries', 'notes',
    ];

    protected $casts = [
        'first_seen_at'   => 'datetime',
        'last_seen_at'    => 'datetime',
        'total_enquiries' => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function leads()
    {
        return $this->hasMany(Lead::class)->orderByDesc('created_at');
    }

    public function activeLeads()
    {
        return $this->leads()->whereNotIn('status', ['booking', 'lost']);
    }

    public function finalLeads()
    {
        return $this->leads()->whereIn('status', ['booking', 'lost']);
    }

    /**
     * Normalize a customer phone number to canonical international digits.
     */
    public static function normalizePhone(string $phone): string
    {
        return phone_canonical($phone);
    }

    /**
     * Does this customer have an active enquiry on the given project?
     */
    public function hasActiveEnquiryForProject(int $projectId): bool
    {
        return $this->leads()
            ->where('project_id', $projectId)
            ->whereNotIn('status', ['booking', 'lost'])
            ->exists();
    }
}