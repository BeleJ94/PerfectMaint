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
    /** Agent de saisie : n'accède qu'à la feuille de saisie des index de ses sites. */
    case Encoder = 'encoder';
    /**
     * Responsable dispatch : pilote l'exploitation (équipes par shift, conducteurs, affectations aux engins),
     * gère les entretiens de bout en bout (OT et plans préventifs) et saisit les index. Ni stock, ni rapports de direction.
     */
    case Dispatcher = 'dispatcher';

    private const PERMISSIONS = [
        'meters.entry' => ['admin', 'manager', 'technician', 'encoder', 'dispatcher'],
        'readings.manage' => ['admin'],
        'dashboard.view' => ['admin', 'manager', 'technician', 'viewer', 'dispatcher'],
        'equipment.view' => ['admin', 'manager', 'technician', 'viewer', 'dispatcher'],
        'equipment.manage' => ['admin', 'manager'],
        'equipment.status' => ['admin', 'manager', 'technician', 'dispatcher'],
        'equipment.reading' => ['admin', 'manager', 'technician', 'dispatcher'],
        'equipment.photo' => ['admin', 'manager', 'technician'],
        'workorder.view' => ['admin', 'manager', 'technician', 'viewer', 'dispatcher'],
        'workorder.create' => ['admin', 'manager', 'technician', 'dispatcher'],
        'workorder.execute' => ['admin', 'manager', 'technician', 'dispatcher'],
        'workorder.manage' => ['admin', 'manager', 'dispatcher'],
        'preventive.view' => ['admin', 'manager', 'technician', 'viewer', 'dispatcher'],
        'preventive.manage' => ['admin', 'manager', 'dispatcher'],
        'preventive.delete' => ['admin'],
        'parts.view' => ['admin', 'manager', 'technician', 'viewer'],
        'parts.manage' => ['admin', 'manager'],
        'field.use' => ['admin', 'manager', 'technician'],
        'personnel.view' => ['admin', 'manager', 'technician', 'viewer', 'dispatcher'],
        'personnel.manage' => ['admin', 'manager', 'dispatcher'],
        'equipment.assign' => ['admin', 'manager', 'dispatcher'],
        'documents.manage' => ['admin', 'manager', 'dispatcher'],
        'activity.view' => ['admin', 'manager'],
        'reports.view' => ['admin', 'manager', 'viewer'],
        'sites.manage' => ['admin'],
        'users.manage' => ['admin'],
        'settings.manage' => ['admin'],
    ];

    public function can(string $permission): bool
    {
        return in_array($this->value, self::PERMISSIONS[$permission] ?? [], true);
    }

    /** Page d'accueil après connexion. */
    public function homePath(): string
    {
        return match ($this) {
            self::Technician => '/terrain',
            self::Encoder => '/saisie',
            default => '/',
        };
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
