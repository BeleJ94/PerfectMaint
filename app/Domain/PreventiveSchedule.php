<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Calcul des échéances de maintenance préventive, au temps (jours) et/ou aux compteurs (heures, km).
 * Le premier seuil atteint déclenche l'échéance.
 */
final class PreventiveSchedule
{
    public function __construct(
        public readonly ?int $intervalDays,
        public readonly ?int $intervalHours,
        public readonly ?int $intervalKm = null,
    ) {
        if (($intervalDays ?? 0) <= 0 && ($intervalHours ?? 0) <= 0 && ($intervalKm ?? 0) <= 0) {
            throw new \InvalidArgumentException('Au moins une périodicité (jours, heures ou km) est requise.');
        }
    }

    public function nextDueDate(DateTimeImmutable $reference): ?DateTimeImmutable
    {
        return $this->intervalDays > 0 ? $reference->modify('+' . $this->intervalDays . ' days') : null;
    }

    public function nextDueHours(?int $referenceHours): ?int
    {
        return $this->intervalHours > 0 ? ($referenceHours ?? 0) + $this->intervalHours : null;
    }

    public function nextDueKm(?int $referenceKm): ?int
    {
        return $this->intervalKm > 0 ? ($referenceKm ?? 0) + $this->intervalKm : null;
    }

    /**
     * Échéance atteinte (ou proche de $horizonDays jours) au regard de la date et des compteurs actuels.
     */
    public static function isDue(
        ?string $nextDueDate,
        ?int $nextDueHours,
        DateTimeImmutable $today,
        ?int $currentHours,
        int $horizonDays = 0,
        ?int $nextDueKm = null,
        ?int $currentKm = null,
    ): bool {
        if ($nextDueDate !== null && new DateTimeImmutable($nextDueDate) <= $today->modify("+$horizonDays days")) {
            return true;
        }
        if ($nextDueHours !== null && $currentHours !== null && $currentHours >= $nextDueHours) {
            return true;
        }

        return $nextDueKm !== null && $currentKm !== null && $currentKm >= $nextDueKm;
    }
}
