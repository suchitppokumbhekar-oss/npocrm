<?php

namespace App\Services;

use Carbon\Carbon;

class BusinessHoursService
{
    public const DEFAULT_START = '10:30';
    public const DEFAULT_END = '20:00';
    public const DEFAULT_WORKING_DAYS = '0,1,2,3,4,5,6';

    public function __construct(private ?SettingsService $settings = null) {}

    /**
     * Normalize an automatically generated follow-up into the configured
     * customer-contact window. Manual scheduling never calls this method.
     */
    public function automatic(?Carbon $dt): ?Carbon
    {
        if (! $dt) return null;

        $dt = $dt->copy();
        $days = $this->workingDays();
        [$startHour, $startMinute] = $this->timeParts($this->startTime());
        [$endHour, $endMinute] = $this->timeParts($this->endTime());

        for ($i = 0; $i < 31; $i++) {
            if (! in_array($dt->dayOfWeek, $days, true)) {
                $dt->addDay()->startOfDay();
                continue;
            }

            $start = $dt->copy()->setTime($startHour, $startMinute, 0);
            $end   = $dt->copy()->setTime($endHour, $endMinute, 0);

            if ($dt->lt($start)) return $start;
            if ($dt->gte($end)) {
                $dt->addDay()->startOfDay();
                continue;
            }

            return $dt;
        }

        // Defensive fallback; the configured defaults guarantee this is never
        // reached in normal operation.
        return $dt->setTime($startHour, $startMinute, 0);
    }

    public function clamp(?Carbon $dt): ?Carbon
    {
        return $this->automatic($dt);
    }

    public function clampUnlessInternal(?Carbon $dt, string $actionTypeKey): ?Carbon
    {
        return $this->automatic($dt);
    }

    public function startTime(): string
    {
        $value = $this->settings?->get('followup_work_start', self::DEFAULT_START) ?? self::DEFAULT_START;
        return $this->normalizeTime((string) $value, self::DEFAULT_START);
    }

    public function endTime(): string
    {
        $value = $this->settings?->get('followup_work_end', self::DEFAULT_END) ?? self::DEFAULT_END;
        return $this->normalizeTime((string) $value, self::DEFAULT_END);
    }

    /** @return int[] Carbon day-of-week values: Sunday=0 ... Saturday=6. */
    public function workingDays(): array
    {
        $raw = (string) ($this->settings?->get('followup_working_days', self::DEFAULT_WORKING_DAYS) ?? self::DEFAULT_WORKING_DAYS);
        $days = array_values(array_unique(array_filter(array_map(
            static fn ($day) => is_numeric(trim((string) $day)) ? (int) trim((string) $day) : null,
            explode(',', $raw)
        ), static fn ($day) => $day !== null && $day >= 0 && $day <= 6)));

        sort($days);
        return $days !== [] ? $days : range(0, 6);
    }

    private function normalizeTime(string $value, string $fallback): string
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $fallback;
        }
        return $value;
    }

    /** @return int[] */
    private function timeParts(string $time): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return [$hour, $minute];
    }
}
