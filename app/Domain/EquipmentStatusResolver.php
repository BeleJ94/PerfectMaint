<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Détermine l'état d'un engin après la clôture d'un OT, selon les OT encore ouverts.
 * Un engin « immobilisé » (décision administrative) ne change d'état que manuellement.
 */
final class EquipmentStatusResolver
{
    /**
     * @param list<array{status: string, immobilizing: int|bool}> $remainingOpen OT encore ouverts sur l'engin
     */
    public static function afterClosure(EquipmentStatus $current, array $remainingOpen): EquipmentStatus
    {
        if ($current === EquipmentStatus::Immobilized) {
            return $current;
        }
        $statuses = array_column($remainingOpen, 'status');
        if (in_array(WorkOrderStatus::InProgress->value, $statuses, true)) {
            return EquipmentStatus::Maintenance;
        }
        if (in_array(WorkOrderStatus::WaitingPart->value, $statuses, true)) {
            return EquipmentStatus::WaitingPart;
        }
        foreach ($remainingOpen as $wo) {
            if ($wo['status'] === WorkOrderStatus::Open->value && (bool) $wo['immobilizing']) {
                return EquipmentStatus::Breakdown;
            }
        }

        return EquipmentStatus::Available;
    }

    /** État imposé par un événement d'OT, sauf si l'engin est immobilisé. */
    public static function onWorkOrderEvent(EquipmentStatus $current, ?EquipmentStatus $imposed): EquipmentStatus
    {
        if ($current === EquipmentStatus::Immobilized || $imposed === null) {
            return $current;
        }

        return $imposed;
    }
}
