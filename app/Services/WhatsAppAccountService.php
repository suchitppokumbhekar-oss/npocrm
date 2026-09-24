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
    public function defaultTypeForUser(User $user): ?string
    {
        $type = $user->default_whatsapp_account_type;

        if (! in_array($type, self::TYPES, true)) return null;

        return $this->accountsForUser((int) $user->id)->contains("account_type", $type)
            ? $type
            : null;
    }


    public function normalize(?string $phone): string
    {
        return phone_canonical($phone);
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
        $requestedDefault = $input["default_account_type"] ?? null;
        $requestedDefault = in_array($requestedDefault, self::TYPES, true) ? $requestedDefault : null;

        $activeTypes = $this->accountsForUser((int) $user->id)->pluck("account_type")->all();

        $user->default_whatsapp_account_type = in_array($requestedDefault, $activeTypes, true)
            ? $requestedDefault
            : null;
        $user->save();

    }
}
