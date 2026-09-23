<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;

/**
 * Canonical same-customer + same-project duplicate guard.
 *
 * A customer's project enquiry is represented by one Lead.  Identity is
 * resolved primarily by customer_id, with normalized phone/email fallbacks so
 * older leads that were created before customer_id was populated are also
 * protected.
 */
class LeadDuplicateGuard
{
    public function findExisting(
        int $projectId,
        ?int $customerId = null,
        ?string $phone = null,
        ?string $email = null,
        ?int $excludeLeadId = null,
    ): ?Lead {
        $normalizedPhone = $phone !== null ? Customer::normalizePhone($phone) : '';
        $normalizedEmail = $email !== null ? mb_strtolower(trim($email)) : '';

        if ($customerId === null && $normalizedPhone === '' && $normalizedEmail === '') {
            return null;
        }

        $phoneVariants = [];
        if ($normalizedPhone !== '') {
            $phoneVariants = [$normalizedPhone, '+' . $normalizedPhone];
            if (strlen($normalizedPhone) === 12 && str_starts_with($normalizedPhone, '91')) {
                $local = substr($normalizedPhone, 2);
                $phoneVariants[] = $local;
                $phoneVariants[] = '0' . $local;
            }
            $phoneVariants = array_values(array_unique($phoneVariants));
        }

        return Lead::query()
            ->with(['project', 'agent.user'])
            ->where('project_id', $projectId)
            ->when($excludeLeadId, fn ($q) => $q->where('id', '!=', $excludeLeadId))
            ->where(function ($q) use ($customerId, $phoneVariants, $normalizedEmail) {
                if ($customerId !== null) {
                    $q->where('customer_id', $customerId);
                }

                if (! empty($phoneVariants)) {
                    $placeholders = implode(',', array_fill(0, count($phoneVariants), '?'));
                    $sql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') IN ({$placeholders})";
                    $method = ($customerId !== null) ? 'orWhereRaw' : 'whereRaw';
                    $q->{$method}($sql, $phoneVariants);
                }

                if ($normalizedEmail !== '') {
                    $method = ($customerId !== null || ! empty($phoneVariants)) ? 'orWhereRaw' : 'whereRaw';
                    $q->{$method}('LOWER(TRIM(email)) = ?', [$normalizedEmail]);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    public function message(Lead $existing): string
    {
        return 'This customer already has an enquiry for ' .
            ($existing->project?->name ?? 'the selected project') .
            ' (Lead #' . $existing->id . '). Open the existing lead instead of creating another one.';
    }
}
