<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentRepository;
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
    ) {
    }

    public function record(int $equipmentId, array $input): int
    {
        $data = Validator::validate($input, [
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'fuel_level' => 'int|min:0|max:100',
            'note' => 'string|max:255',
        ]);
        if ($data['hour_meter'] === null && $data['odometer_km'] === null && $data['fuel_level'] === null) {
            throw Validator::fail('hour_meter', 'reading_empty');
        }

        return $this->db->transaction(function () use ($equipmentId, $data): int {
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            foreach (['hour_meter', 'odometer_km'] as $counter) {
                if ($data[$counter] !== null && $row[$counter] !== null && $data[$counter] < (int) $row[$counter]) {
                    throw Validator::fail($counter, 'meter_decrease', ['current' => format_number($row[$counter])]);
                }
            }
            $this->equipment->applyMeters($equipmentId, $data['hour_meter'], $data['odometer_km'], $data['fuel_level']);
            $id = $this->equipment->addReading(
                $equipmentId, $data['hour_meter'], $data['odometer_km'], $data['fuel_level'], $data['note'], $this->auth->user()?->id
            );
            $before = array_intersect_key($row, array_flip(['hour_meter', 'odometer_km', 'fuel_level']));
            $after = array_filter(
                ['hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km'], 'fuel_level' => $data['fuel_level']],
                static fn ($v) => $v !== null
            );
            $this->logger->log('reading', 'equipment', $equipmentId, (int) $row['site_id'], $before, $after + $before, $row['code']);

            return $id;
        });
    }
}
