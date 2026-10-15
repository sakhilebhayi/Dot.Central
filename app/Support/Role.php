<?php

namespace App\Support;

/**
 * The four control-room roles, stored as Jetstream team roles
 * (team_user.role). Team owners are administrators implicitly.
 * See docs/dot-central/07-security-and-permissions.md §2.
 */
enum Role: string
{
    case Operator = 'operator';
    case Supervisor = 'supervisor';
    case Manager = 'manager';
    case Administrator = 'administrator';

    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Control Room Operator',
            self::Supervisor => 'Shift Supervisor',
            self::Manager => 'Operations Manager',
            self::Administrator => 'Administrator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Operator => 'Captures operational records, acknowledges alerts, runs and closes shifts in assigned control rooms.',
            self::Supervisor => 'Everything an operator can do, plus approvals, escalations, shift sign-off, exports and the audit log.',
            self::Manager => 'Read-only view of every control room in the team, conflict resolution, exports and the audit log.',
            self::Administrator => 'Manages control rooms, Dot.Mines connections, catalogues, users and roles; does not capture operational data.',
        };
    }

    /**
     * Jetstream permission keys attached to the role (used by Jetstream's
     * own UI and API-token scoping; application policies use the role).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Operator => ['read', 'create', 'update'],
            self::Supervisor => ['read', 'create', 'update', 'delete'],
            self::Manager => ['read'],
            self::Administrator => ['read', 'create', 'update', 'delete'],
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
