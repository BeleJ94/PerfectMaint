<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Rôles et permissions. Les permissions s'appliquent toujours DANS le périmètre de sites de l'utilisateur.
 */
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Technician = 'technician';
    case Viewer = 'viewer';

    private const PERMISSIONS = [
        'dashboard.view' => ['admin', 'manager', 'technician', 'viewer'],
        'equipment.view' => ['admin', 'manager', 'technician', 'viewer'],
        'equipment.manage' => ['admin', 'manager'],
        'equipment.status' => ['admin', 'manager', 'technician'],
        'workorder.view' => ['admin', 'manager', 'technician', 'viewer'],
        'workorder.create' => ['admin', 'manager', 'technician'],
        'workorder.execute' => ['admin', 'manager', 'technician'],
        'workorder.manage' => ['admin', 'manager'],
        'preventive.view' => ['admin', 'manager', 'technician', 'viewer'],
        'preventive.manage' => ['admin', 'manager'],
        'parts.view' => ['admin', 'manager', 'technician', 'viewer'],
        'parts.manage' => ['admin', 'manager'],
        'field.use' => ['admin', 'manager', 'technician'],
        'activity.view' => ['admin', 'manager'],
        'sites.manage' => ['admin'],
        'users.manage' => ['admin'],
    ];

    public function can(string $permission): bool
    {
        return in_array($this->value, self::PERMISSIONS[$permission] ?? [], true);
    }

    public function labelKey(): string
    {
        return 'role.' . $this->value;
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
