<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentRepository;
use App\Repositories\FluidRepository;
use App\Repositories\PartRepository;
use App\Security\Auth;

/**
 * Appoint ou vidange d'un fluide sur un engin : sortie du stock du site (pas de stock négatif),
 * trace avec les compteurs de l'engin pour le calcul des consommations (litres / 100 h).
 */
final class FluidService
{
    public const KINDS = ['top_up', 'change'];

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly PartRepository $parts,
        private readonly FluidRepository $fluids,
        private readonly StockService $stock,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public function log(int $equipmentId, array $input): int
    {
        $data = Validator::validate($input, [
            'part_id' => 'required|int',
            'quantity' => 'required|numeric|min:0.1|max:5000',
            'kind' => 'required|in:' . implode(',', self::KINDS),
            'note' => 'string|max:255',
        ]);

        return $this->db->transaction(function () use ($equipmentId, $data): int {
            $equipment = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $part = $this->parts->find($data['part_id']);
            if ($part === null || !in_array($part['part_type'], ['lubricant', 'fuel'], true) || !(int) $part['is_active']) {
                throw Validator::fail('part_id', 'not_fluid');
            }
            $siteId = (int) $equipment['site_id'];
            $this->stock->move((int) $part['id'], $siteId, 'out', $data['quantity'], $equipment['code'] . ' – ' . t('fluid.' . $data['kind']), null, $equipmentId);
            $id = $this->fluids->add([
                'equipment_id' => $equipmentId,
                'site_id' => $siteId,
                'part_id' => (int) $part['id'],
                'kind' => $data['kind'],
                'quantity' => $data['quantity'],
                'hour_meter' => $equipment['hour_meter'],
                'odometer_km' => $equipment['odometer_km'],
                'note' => $data['note'],
                'logged_by' => $this->auth->user()?->id,
            ]);
            $this->logger->log(
                'fluid_' . $data['kind'], 'equipment', $equipmentId, $siteId, null,
                ['part' => $part['reference'], 'quantity' => $data['quantity'], 'hour_meter' => $equipment['hour_meter']],
                $equipment['code'] . ' : ' . $data['quantity'] . ' ' . $part['unit'] . ' ' . $part['name']
            );

            return $id;
        });
    }
}
