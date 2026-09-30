<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Cycle de vie d'un ordre de travail (OT) et impact sur l'état de l'engin.
 */
enum WorkOrderStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingPart = 'waiting_part';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingPart, self::Done, self::Cancelled],
            self::InProgress => [self::WaitingPart, self::Done, self::Cancelled],
            self::WaitingPart => [self::InProgress, self::Done, self::Cancelled],
            self::Done, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /**
     * État que doit prendre l'engin quand un OT passe dans ce statut.
     * null = l'OT n'impose rien (l'état est recalculé depuis les autres OT ouverts).
     */
    public function equipmentStatus(bool $immobilizing): ?EquipmentStatus
    {
        return match ($this) {
            self::Open => $immobilizing ? EquipmentStatus::Breakdown : null,
            self::InProgress => EquipmentStatus::Maintenance,
            self::WaitingPart => EquipmentStatus::WaitingPart,
            self::Done, self::Cancelled => null,
        };
    }

    public function labelKey(): string
    {
        return 'wo_status.' . $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Open->value, self::InProgress->value, self::WaitingPart->value];
    }
}
