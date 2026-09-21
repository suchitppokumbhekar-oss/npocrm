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
     * Normalize an Indian phone number to 10 digits.
     * Same logic as LeadIntakeService and ContactService.
     */
    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/\D/', '', $phone);
        if (str_starts_with($p, '91') && strlen($p) === 12) $p = substr($p, 2);
        if (str_starts_with($p, '0')  && strlen($p) === 11) $p = substr($p, 1);
        return $p;
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