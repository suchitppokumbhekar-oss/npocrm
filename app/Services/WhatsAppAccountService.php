<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWhatsAppAccount;

class WhatsAppAccountService
{
    public const TYPES = ['personal', 'business'];

    public function accountsForUser(int $userId)
    {
        return UserWhatsAppAccount::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('account_type', self::TYPES)
            ->orderByRaw("FIELD(account_type, 'personal', 'business')")
            ->get();
    }

    public function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') return '';

        // Keep international numbers intact; convert common Indian formats.
        if (strlen($digits) === 10) return '91' . $digits;
        if (strlen($digits) === 11 && $digits[0] === '0') return '91' . substr($digits, 1);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) return $digits;

        return ltrim($digits, '0');
    }

    public function isValid(?string $phone): bool
    {
        $digits = $this->normalize($phone);
        return $digits !== '' && strlen($digits) >= 8 && strlen($digits) <= 15;
    }

    public function saveForUser(User $user, array $input): void
    {
        foreach (self::TYPES as $type) {
            $raw = trim((string) ($input[$type] ?? ''));
            $phone = $raw === '' ? '' : $this->normalize($raw);

            if ($phone === '') {
                UserWhatsAppAccount::where('user_id', $user->id)
                    ->where('account_type', $type)
                    ->update(['is_active' => false, 'phone' => null]);
                continue;
            }

            UserWhatsAppAccount::updateOrCreate(
                ['user_id' => $user->id, 'account_type' => $type],
                ['phone' => $phone, 'is_active' => true]
            );
        }
    }
}
