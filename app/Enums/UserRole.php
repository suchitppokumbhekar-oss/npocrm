<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN        = 'admin';
    case TEAM_MANAGER = 'team_manager';
    case AGENT        = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN        => 'Admin',
            self::TEAM_MANAGER => 'Team Manager',
            self::AGENT        => 'Agent',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ADMIN        => '👑',
            self::TEAM_MANAGER => '🎯',
            self::AGENT        => '👤',
        };
    }

    public function isAdmin(): bool       { return $this === self::ADMIN; }
    public function isTeamManager(): bool { return $this === self::TEAM_MANAGER; }
    public function isAgent(): bool       { return $this === self::AGENT; }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}