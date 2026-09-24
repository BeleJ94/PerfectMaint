<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\EquipmentStatus;
use App\Repositories\EquipmentRepository;
use App\Security\Auth;

final class EquipmentService
{
    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public static function rules(): array
    {
        return [
            'site_id' => 'required|int',
            'category_id' => 'int',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'brand' => 'string|max:80',
            'model' => 'string|max:80',
            'serial_number' => 'string|max:80',
            'commissioned_at' => 'date',
            'hour_meter' => 'int|min:0|max:999999',
            'notes' => 'string|max:5000',
        ];
    }

    public function create(array $input): int
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        $this->equipment->scope()->assertAllows($data['site_id']);
        if ($this->equipment->codeExists($data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->equipment->create($data);
            $this->equipment->addStatusHistory($id, null, EquipmentStatus::Available->value, null, null, $this->auth->user()?->id);
            $this->logger->log('create', 'equipment', $id, $data['site_id'], null, $data, $data['code'] . ' – ' . $data['name']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        $this->equipment->scope()->assertAllows($data['site_id']);
        if ($this->equipment->codeExists($data['code'], $id)) {
            throw Validator::fail('code', 'unique');
        }

        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            $this->equipment->update($id, $data);
            $after = $this->equipment->findForUpdate($id);
            $this->logger->log('update', 'equipment', $id, $data['site_id'], $before, $after, $data['code']);
        });
    }

    /** Changement d'état manuel (ex. immobilisation réglementaire), motif obligatoire. */
    public function changeStatus(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'status' => 'required|in:' . implode(',', EquipmentStatus::values()),
            'reason' => 'required|string|max:255',
        ]);
        $this->db->transaction(function () use ($id, $data): void {
            $row = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            $this->applyStatus($row, EquipmentStatus::from($data['status']), $data['reason'], null);
        });
    }

    /**
     * Applique un nouvel état (historique + journal). À appeler dans une transaction,
     * avec la ligne obtenue par findForUpdate().
     */
    public function applyStatus(array $equipmentRow, EquipmentStatus $status, ?string $reason, ?int $workOrderId): bool
    {
        if ($equipmentRow['status'] === $status->value) {
            return false;
        }
        $id = (int) $equipmentRow['id'];
        $this->equipment->updateStatus($id, $status->value);
        $this->equipment->addStatusHistory($id, $equipmentRow['status'], $status->value, $reason, $workOrderId, $this->auth->user()?->id);
        $this->logger->log(
            'status',
            'equipment',
            $id,
            (int) $equipmentRow['site_id'],
            ['status' => $equipmentRow['status']],
            ['status' => $status->value, 'reason' => $reason, 'work_order_id' => $workOrderId],
            $equipmentRow['code'] . ' : ' . $equipmentRow['status'] . ' → ' . $status->value
        );

        return true;
    }

    public function archive(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $row = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            if ((int) $row['is_archived'] === 1) {
                return;
            }
            $this->equipment->setArchived($id, true);
            $this->logger->log('archive', 'equipment', $id, (int) $row['site_id'], ['is_archived' => 0], ['is_archived' => 1], $row['code']);
        });
    }
}
