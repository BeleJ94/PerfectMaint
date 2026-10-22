<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentRepository;
use App\Repositories\FuelGaugeRepository;
use App\Security\Auth;

/**
 * Relevés de compteurs (heures moteur, kilométrage) et du niveau de carburant.
 * Les compteurs ne peuvent pas reculer : une valeur inférieure au dernier relevé est refusée.
 */
final class MeterService
{
    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        private readonly FuelGaugeRepository $gauges,
    ) {
    }

    public function record(int $equipmentId, array $input): int
    {
        $data = Validator::validate($input, [
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'fuel_level' => 'int|min:0|max:100',
            'fuel_mark' => 'numeric|min:0|max:20',
            'note' => 'string|max:255',
        ]);
        if ($data['hour_meter'] === null && $data['odometer_km'] === null && $data['fuel_level'] === null && $data['fuel_mark'] === null) {
            throw Validator::fail('hour_meter', 'reading_empty');
        }

        return $this->db->transaction(function () use ($equipmentId, $data): int {
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $fuel = $this->fuel($equipmentId, $data);
            $data['fuel_level'] = $fuel['fuel_level'] ?? null;
            foreach (['hour_meter', 'odometer_km'] as $counter) {
                if ($data[$counter] !== null && $row[$counter] !== null && $data[$counter] < (int) $row[$counter]) {
                    throw Validator::fail($counter, 'meter_decrease', ['current' => format_number($row[$counter])]);
                }
            }
            $this->equipment->applyMeters($equipmentId, $data['hour_meter'], $data['odometer_km'], $data['fuel_level']);
            $id = $this->equipment->addReading(
                $equipmentId, $data['hour_meter'], $data['odometer_km'], $data['fuel_level'], $data['note'], $this->auth->user()?->id
            );
            $this->storeFuel($id, $equipmentId, $fuel);
            $before = array_intersect_key($row, array_flip(['hour_meter', 'odometer_km', 'fuel_level']));
            $after = array_filter(
                ['hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km'], 'fuel_level' => $data['fuel_level']],
                static fn ($v) => $v !== null
            );
            if ($fuel !== null && $fuel['fuel_liters'] !== null) {
                $after['fuel_liters'] = $fuel['fuel_liters'];
            }
            $this->logger->log('reading', 'equipment', $equipmentId, (int) $row['site_id'], $before, $after + $before, $row['code']);

            return $id;
        });
    }

    /**
     * Niveau de carburant lu sur l'indicateur de l'engin : graduation saisie (« 2 » sur 4 tirets)
     * ou pourcentage (indicateur numérique ou non configuré). Null si rien n'est saisi.
     *
     * @return array{fuel_level: int, fuel_mark: float|null, fuel_marks: int|null, fuel_liters: float|null}|null
     */
    public function fuel(int $equipmentId, array $data): ?array
    {
        $gauge = $this->gauges->gaugeFor($equipmentId);
        if (($data['fuel_mark'] ?? null) !== null) {
            if (!$gauge->usesMarks()) {
                throw Validator::fail('fuel_mark', 'fuel_gauge_missing');
            }
            if (!$gauge->acceptsMark((float) $data['fuel_mark'])) {
                throw Validator::fail('fuel_mark', 'fuel_mark_range', ['max' => $gauge->marks]);
            }

            return $gauge->fromMark((float) $data['fuel_mark']);
        }

        return ($data['fuel_level'] ?? null) !== null ? $gauge->fromPercent((int) $data['fuel_level']) : null;
    }

    /** Détail de la lecture (graduation, litres) sur le relevé, et litres courants sur l'engin. */
    public function storeFuel(int $readingId, int $equipmentId, ?array $fuel): void
    {
        if ($fuel === null) {
            return;
        }
        $this->gauges->saveReadingDetails($readingId, $fuel['fuel_mark'], $fuel['fuel_marks'], $fuel['fuel_liters']);
        $this->gauges->saveEquipmentLiters($equipmentId, $fuel['fuel_liters']);
    }
}
