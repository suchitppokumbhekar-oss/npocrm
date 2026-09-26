<?php

if (! function_exists('inr')) {
    function inr($value, int $decimals = 2, bool $withSymbol = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $num        = (float) $value;
        $isNegative = $num < 0;
        $num        = abs($num);

        $decPart = '';
        if ($decimals > 0) {
            $decPart = '.' . str_pad(
                (string) round(($num - floor($num)) * pow(10, $decimals)),
                $decimals,
                '0',
                STR_PAD_LEFT
            );
        }

        $intStr = (string) (int) floor($num);
        $len    = strlen($intStr);

        if ($len <= 3) {
            $grouped = $intStr;
        } else {
            $lastThree = substr($intStr, -3);
            $rest      = substr($intStr, 0, -3);
            $rest      = preg_replace('/(\d)(?=(\d{2})+$)/', '$1,', $rest);
            $grouped   = $rest . ',' . $lastThree;
        }

        $result = $grouped . $decPart;

        return ($isNegative ? '-' : '') . ($withSymbol ? '₹' : '') . $result;
    }
}

if (! function_exists('inr_num')) {
    function inr_num($value, int $decimals = 0): string
    {
        return inr($value, $decimals, false);
    }
}

if (! function_exists('phone_tel')) {
    /**
     * Normalize a phone number for tel: links.
     *   9876543210      → +919876543210
     *   09876543210     → +919876543210
     *   919876543210    → +919876543210
     *   +91 98765 43210 → +919876543210
     */
    function phone_tel(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return '';
        }

        // 10-digit Indian mobile
        if (strlen($digits) === 10) {
            return '+91' . $digits;
        }

        // 11 digits with leading 0
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '+91' . substr($digits, 1);
        }

        // 12 digits with 91 prefix
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return '+' . $digits;
        }

        // Any other — assume already has country code
        return '+' . ltrim($digits, '0');
    }
}

if (! function_exists('phone_wa')) {
    /**
     * Normalize a phone number for wa.me links (no +, no spaces).
     */
    function phone_wa(?string $phone): string
    {
        return ltrim(phone_tel($phone), '+');
    }
}

if (! function_exists('phone_canonical')) {
    function phone_canonical(?string $phone, ?string $countryCode = null): string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') return '';
        if (str_starts_with($digits, '00')) return substr($digits, 2);
        if (str_starts_with($raw, '+')) return $digits;
        $cc = preg_replace('/\D/', '', (string) $countryCode);
        if ($cc !== '') {
            $local = ltrim($digits, '0');
            if (str_starts_with($local, $cc) && strlen($local) > strlen($cc) + 6) return $local;
            return $cc . $local;
        }
        if (strlen($digits) === 10) return '91' . $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) return '91' . substr($digits, 1);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) return $digits;
        return ltrim($digits, '0');
    }
}

if (! function_exists('phone_display')) {
    function phone_display(?string $phone, ?string $countryCode = null): string
    {
        $canonical = phone_canonical($phone, $countryCode);
        return $canonical === '' ? '' : '+' . $canonical;
    }
}
