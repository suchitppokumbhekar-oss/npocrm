<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;

class CustomerService
{
    /**
     * Find or create a customer by phone. Normalizes the phone first.
     * Updates the customer's name/email if better data arrives.
     */
    public function findOrCreateByPhone(string $phone, array $attrs = []): ?Customer
    {
        $normalized = Customer::normalizePhone($phone);
        if (strlen($normalized) < 8) return null;

        $customer = Customer::where('phone', $normalized)->first();

        if ($customer) {
            $updates = ['last_seen_at' => now()];

            // Update name if we have a better one (longer, not 'Unknown')
            if (! empty($attrs['name'])) {
                $newName = trim((string) $attrs['name']);
                $oldName = trim((string) $customer->name);
                if ($newName !== '' && ($oldName === '' || $oldName === 'Unknown')) {
                    $updates['name'] = $newName;
                } elseif ($newName !== '' && strlen($newName) > strlen($oldName)) {
                    $updates['name'] = $newName;
                }
            }

            // Fill email if we don't have one yet
            if (! empty($attrs['email']) && empty($customer->email)) {
                $updates['email'] = $attrs['email'];
            }

            $customer->update($updates);
            return $customer->fresh();
        }

        return Customer::create([
            'name'            => trim((string) ($attrs['name']  ?? 'Unknown')) ?: 'Unknown',
            'phone'           => $normalized,
            'email'           => $attrs['email'] ?? null,
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'total_enquiries' => 0,
        ]);
    }

    /**
     * Link a lead to a customer and refresh the counter.
     */
    public function linkLead(Lead $lead, Customer $customer): void
    {
        $lead->update(['customer_id' => $customer->id]);

        $customer->update([
            'last_seen_at'    => now(),
            'total_enquiries' => $customer->leads()->count(),
        ]);
    }

    /**
     * Refresh the customer's enquiry counter from linked leads.
     */
    public function refreshCounters(Customer $customer): void
    {
        $customer->update([
            'total_enquiries' => $customer->leads()->count(),
            'last_seen_at'    => $customer->leads()->max('created_at') ?? $customer->last_seen_at,
            'first_seen_at'   => $customer->leads()->min('created_at') ?? $customer->first_seen_at,
        ]);
    }
}