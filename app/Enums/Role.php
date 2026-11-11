<?php

namespace App\Enums;

/**
 * The roles an account may hold (docs/architecture.md §1 "Accounts and
 * roles", ADR 010): the one list of their names. Code passes the enum,
 * never the string. A role opens an area; ownership still decides rows.
 */
enum Role: string
{
    case Teacher = 'teacher';
    case Parent = 'parent';

    /**
     * The `users` column that keeps when the account accepted this role's
     * sentence (§7 "Agreements", ADR 013).
     */
    public function agreementColumn(): string
    {
        return match ($this) {
            self::Teacher => 'teacher_agreed_at',
            self::Parent => 'parent_agreed_at',
        };
    }

    /** The name of the route the area opens on (`/start` lands there). */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Teacher => 'classes.index',
            self::Parent => 'children.index',
        };
    }

    /**
     * The package's `role` middleware for an area: `role:teacher`, or
     * `role:teacher|parent` for the shared one.
     */
    public static function middleware(self ...$roles): string
    {
        return 'role:'.implode('|', array_map(static fn (self $role): string => $role->value, $roles));
    }
}
