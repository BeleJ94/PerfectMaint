<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Calcul des échéances de maintenance préventive, au temps (jours) et/ou au compteur (heures).
 * Le premier des deux seuils atteint déclenche l'échéance.
 */
final class PreventiveSchedule
{
    public function __construct(
        public readonly ?int $intervalDays,
        public readonly ?int $intervalHours,
    ) {
        if (($intervalDays ?? 0) <= 0 && ($intervalHours ?? 0) <= 0) {
            throw new \InvalidArgumentException('Au moins une périodicité (jours ou heures) est requise.');
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

    /**
     * Échéance atteinte (ou proche de $horizonDays jours) au regard de la date et du compteur actuel.
     */
    public static function isDue(
        ?string $nextDueDate,
        ?int $nextDueHours,
        DateTimeImmutable $today,
        ?int $currentHours,
        int $horizonDays = 0,
    ): bool {
        if ($nextDueDate !== null && new DateTimeImmutable($nextDueDate) <= $today->modify("+$horizonDays days")) {
            return true;
        }

        return $nextDueHours !== null && $currentHours !== null && $currentHours >= $nextDueHours;
    }
}
