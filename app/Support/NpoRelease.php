<?php

namespace App\Support;

final class NpoRelease
{
    public const VERSION = '1.0.1';
    public const RELEASE_DATE = '2026-09-14';
    public const RELEASE_NAME = 'Maintenance & Release Foundation — Laravel 13 Compatibility Fix';

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function releaseDate(): string
    {
        return self::RELEASE_DATE;
    }

    public static function releaseName(): string
    {
        return self::RELEASE_NAME;
    }
}
