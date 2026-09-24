<?php

declare(strict_types=1);

namespace App\Domain;

enum EquipmentStatus: string
{
    case Available = 'available';
    case Breakdown = 'breakdown';
    case Maintenance = 'maintenance';
    case WaitingPart = 'waiting_part';
    case Immobilized = 'immobilized';

    /** Couleurs d'état imposées par la charte. */
    public function color(): string
    {
        return match ($this) {
            self::Available => '#1E7A4C',
            self::Breakdown => '#B42318',
            self::Maintenance => '#1F4E79',
            self::WaitingPart => '#B54708',
            self::Immobilized => '#8D9098',
        };
    }

    public function labelKey(): string
    {
        return 'status.' . $this->value;
    }

    /** L'engin est-il utilisable en exploitation ? */
    public function isOperational(): bool
    {
        return $this === self::Available;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
