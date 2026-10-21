<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\EquipmentStatus;
use App\Domain\EquipmentStatusResolver;
use App\Domain\PreventiveSchedule;
use App\Domain\WorkOrderStatus;
use App\Repositories\AssignmentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PartRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkOrderRepository;
use App\Security\Auth;
use DateTimeImmutable;

/**
 * Ordres de travail : création, cycle de vie, consommation de pièces.
 * Chaque opération est transactionnelle et répercute l'état de l'engin.
 */
final class WorkOrderService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];
    public const TYPES = ['corrective', 'preventive'];

    /** Causes de panne : distinguer l'usure normale, l'utilisation et la prise en charge maintenance. */
    public const FAILURE_CAUSES = ['wear', 'misuse', 'maintenance_lapse', 'accident', 'component_failure', 'external', 'unknown'];

    public function __construct(
        private readonly Database $db,
        private readonly WorkOrderRepository $workOrders,
        private readonly EquipmentRepository $equipment,
        private readonly EquipmentService $equipmentService,
        private readonly PreventivePlanRepository $plans,
        private readonly PartRepository $parts,
        private readonly UserRepository $users,
        private readonly StockService $stock,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        private readonly AssignmentRepository $assignments,
        private readonly PersonnelRepository $personnel,
    ) {
    }

    public function create(array $input): int
    {
        $data = Validator::validate($input, [
            'equipment_id' => 'required|int',
            'title' => 'required|string|max:150',
            'description' => 'string|max:5000',
            'type' => 'in:' . implode(',', self::TYPES),
            'priority' => 'in:' . implode(',', self::PRIORITIES),
            'immobilizing' => 'bool',
            'assigned_to' => 'int',
            'due_date' => 'date',
        ]);
        $data['type'] ??= 'corrective';
        $data['priority'] ??= 'normal';

        return $this->db->transaction(function () use ($data): int {
            $equipment = $this->equipment->findForUpdate($data['equipment_id'])
                ?? throw Validator::fail('equipment_id', 'exists');
            if ((int) $equipment['is_archived'] === 1) {
                throw Validator::fail('equipment_id', 'exists');
            }
            $siteId = (int) $equipment['site_id'];
            $data['assigned_to'] = $this->resolveAssignee($data['assigned_to'], $siteId);

            return $this->insert($equipment, $data + ['site_id' => $siteId]);
        });
    }

    /** OT préventif issu d'un plan arrivé à échéance. */
    public function createFromPlan(array $plan): int
    {
        return $this->db->transaction(function () use ($plan): int {
            $equipment = $this->equipment->findForUpdate((int) $plan['equipment_id']) ?? throw HttpException::notFound();

            return $this->insert($equipment, [
                'site_id' => (int) $equipment['site_id'],
                'preventive_plan_id' => (int) $plan['id'],
                'type' => 'preventive',
                'priority' => 'normal',
                'immobilizing' => false,
                'title' => $plan['title'],
                'description' => $plan['instructions'],
                'assigned_to' => null,
                'due_date' => $plan['next_due_date'] ?? date('Y-m-d'),
            ]);
        });
    }

    private function insert(array $equipment, array $data): int
    {
        $data['reference'] = $this->workOrders->nextReference((int) date('Y'));
        $data['equipment_id'] = (int) $equipment['id'];
        $data['reported_by'] = $this->auth->user()?->id;
        $data['hour_meter'] = $equipment['hour_meter'];
        $data['odometer_km'] = $equipment['odometer_km'] ?? null;
        // Conducteur en poste au moment du signalement : rattache la panne à l'utilisation de l'engin.
        $operator = $this->assignments->operatorAt((int) $equipment['id'], date('Y-m-d H:i:s'));
        $data['operator_id'] = $operator !== null ? (int) $operator['personnel_id'] : null;
        $data['operator_name'] = $operator['name'] ?? null;
        $id = $this->workOrders->create($data);

        $imposed = WorkOrderStatus::Open->equipmentStatus((bool) $data['immobilizing']);
        $target = EquipmentStatusResolver::onWorkOrderEvent(EquipmentStatus::from($equipment['status']), $imposed);
        $this->equipmentService->applyStatus($equipment, $target, $data['reference'], $id);

        $this->logger->log('create', 'work_order', $id, $data['site_id'], null, $data, $data['reference'] . ' – ' . $data['title']);

        return $id;
    }

    /** Modification des informations de planification (responsables). */
    public function update(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'title' => 'required|string|max:150',
            'description' => 'string|max:5000',
            'priority' => 'required|in:' . implode(',', self::PRIORITIES),
            'immobilizing' => 'bool',
            'assigned_to' => 'int',
            'due_date' => 'date',
        ]);
        $this->db->transaction(function () use ($id, $data): void {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            if (WorkOrderStatus::from($wo['status'])->isClosed()) {
                throw Validator::fail('status', 'closed');
            }
            $data['assigned_to'] = $this->resolveAssignee($data['assigned_to'], (int) $wo['site_id']);
            $this->workOrders->update($id, $data);

            // Un OT ouvert devenu immobilisant met l'engin en panne (et inversement).
            if ($wo['status'] === WorkOrderStatus::Open->value && (bool) $wo['immobilizing'] !== $data['immobilizing']) {
                $equipment = $this->equipment->findForUpdate((int) $wo['equipment_id']);
                $remaining = $this->workOrders->openForEquipment((int) $wo['equipment_id'], $id);
                $remaining[] = ['status' => 'open', 'immobilizing' => $data['immobilizing']];
                $target = EquipmentStatusResolver::afterClosure(EquipmentStatus::from($equipment['status']), $remaining);
                $this->equipmentService->applyStatus($equipment, $target, $wo['reference'], $id);
            }
            $this->logger->log('update', 'work_order', $id, (int) $wo['site_id'], $wo, $data + $wo, $wo['reference']);
        });
    }

    /**
     * Changement de statut : open → in_progress → waiting_part → done / cancelled.
     */
    public function transition(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'status' => 'required|in:' . implode(',', WorkOrderStatus::values()),
            'resolution' => 'string|max:5000',
            'labor_minutes' => 'int|min:0|max:100000',
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'failure_cause' => 'in:' . implode(',', self::FAILURE_CAUSES),
        ]);
        $target = WorkOrderStatus::from($data['status']);
        if ($target === WorkOrderStatus::Done && $data['resolution'] === null) {
            throw Validator::fail('resolution', 'required');
        }
        $user = $this->auth->user();
        if ($target === WorkOrderStatus::Cancelled && !($user?->can('workorder.manage') ?? true)) {
            throw HttpException::forbidden();
        }

        $this->db->transaction(function () use ($id, $data, $target, $user): void {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            $this->assertCanExecute($wo);
            $from = WorkOrderStatus::from($wo['status']);
            if (!$from->canTransitionTo($target)) {
                throw Validator::fail('status', 'transition');
            }

            $now = date('Y-m-d H:i:s');
            $fields = ['status' => $target->value];
            if ($target === WorkOrderStatus::InProgress && $wo['started_at'] === null) {
                $fields['started_at'] = $now;
            }
            if ($target === WorkOrderStatus::InProgress && $wo['assigned_to'] === null && $user !== null) {
                $fields['assigned_to'] = $user->id; // prise en charge
            }
            if ($target->isClosed()) {
                $fields['completed_at'] = $now;
            }
            if ($target === WorkOrderStatus::Done && $data['labor_minutes'] === null) {
                $data['labor_minutes'] = $this->workOrders->technicianMinutes($id);
            }
            foreach (['resolution', 'labor_minutes', 'hour_meter', 'odometer_km', 'failure_cause'] as $key) {
                if ($data[$key] !== null) {
                    $fields[$key] = $data[$key];
                }
            }
            $this->workOrders->update($id, $fields);

            $equipment = $this->equipment->findForUpdate((int) $wo['equipment_id']) ?? throw HttpException::notFound();
            if ($data['hour_meter'] !== null) {
                $this->equipment->raiseHourMeter((int) $equipment['id'], $data['hour_meter']);
            }
            if ($data['odometer_km'] !== null) {
                $this->equipment->raiseOdometer((int) $equipment['id'], $data['odometer_km']);
            }
            if ($data['hour_meter'] !== null || $data['odometer_km'] !== null) {
                $this->equipment->addReading((int) $equipment['id'], $data['hour_meter'], $data['odometer_km'], null, $wo['reference'], $user?->id);
            }
            $current = EquipmentStatus::from($equipment['status']);
            $newStatus = $target->isClosed()
                ? EquipmentStatusResolver::afterClosure($current, $this->workOrders->openForEquipment((int) $equipment['id'], $id))
                : EquipmentStatusResolver::onWorkOrderEvent($current, $target->equipmentStatus((bool) $wo['immobilizing']));
            $this->equipmentService->applyStatus($equipment, $newStatus, $wo['reference'], $id);

            if ($target === WorkOrderStatus::Done && $wo['preventive_plan_id'] !== null) {
                $hours = $data['hour_meter'] ?? ($equipment['hour_meter'] !== null ? (int) $equipment['hour_meter'] : null);
                $km = $data['odometer_km'] ?? ($equipment['odometer_km'] !== null ? (int) $equipment['odometer_km'] : null);
                $this->completePlan((int) $wo['preventive_plan_id'], $hours, $km);
            }

            $this->logger->log(
                'transition',
                'work_order',
                $id,
                (int) $wo['site_id'],
                array_intersect_key($wo, $fields),
                $fields,
                $wo['reference'] . ' : ' . $from->value . ' → ' . $target->value
            );
        });
    }

    /** Recalage de l'échéance d'un plan après exécution de son OT préventif. */
    private function completePlan(int $planId, ?int $hours, ?int $km): void
    {
        $plan = $this->plans->findForUpdate($planId);
        if ($plan === null) {
            return;
        }
        $schedule = new PreventiveSchedule(
            $plan['interval_days'] !== null ? (int) $plan['interval_days'] : null,
            $plan['interval_hours'] !== null ? (int) $plan['interval_hours'] : null,
            $plan['interval_km'] !== null ? (int) $plan['interval_km'] : null,
        );
        $today = new DateTimeImmutable('today');
        $this->plans->markDone(
            $planId,
            $today->format('Y-m-d'),
            $hours,
            $schedule->nextDueDate($today)?->format('Y-m-d'),
            $schedule->nextDueHours($hours),
            $km,
            $schedule->nextDueKm($km),
        );
        $this->logger->log(
            'done',
            'preventive_plan',
            $planId,
            (int) $plan['site_id'],
            ['last_done_at' => $plan['last_done_at'], 'next_due_date' => $plan['next_due_date'], 'next_due_hours' => $plan['next_due_hours'],
                'next_due_km' => $plan['next_due_km']],
            ['last_done_at' => $today->format('Y-m-d'), 'next_due_date' => $schedule->nextDueDate($today)?->format('Y-m-d'),
                'next_due_hours' => $schedule->nextDueHours($hours), 'next_due_km' => $schedule->nextDueKm($km)],
            $plan['title']
        );
    }

    /** Consommation d'une pièce sur l'OT : sortie de stock du site de l'OT. */
    public function consumePart(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'part_id' => 'required|int',
            'quantity' => 'required|numeric|min:0.01|max:100000',
        ]);
        $this->db->transaction(function () use ($id, $data): void {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            $this->assertCanExecute($wo);
            if (WorkOrderStatus::from($wo['status'])->isClosed()) {
                throw Validator::fail('status', 'closed');
            }
            $part = $this->parts->find($data['part_id']) ?? throw Validator::fail('part_id', 'exists');
            $this->stock->move((int) $part['id'], (int) $wo['site_id'], 'out', $data['quantity'], $wo['reference'], $id);
            $meters = $this->equipment->find((int) $wo['equipment_id']);
            $this->workOrders->addPart(
                $id, (int) $part['id'], $data['quantity'], (float) $part['unit_cost'], $this->auth->user()?->id,
                isset($meters['hour_meter']) ? (int) $meters['hour_meter'] : null,
                isset($meters['odometer_km']) ? (int) $meters['odometer_km'] : null,
            );
            $this->logger->log(
                'part_consumed',
                'work_order',
                $id,
                (int) $wo['site_id'],
                null,
                ['part_id' => (int) $part['id'], 'reference' => $part['reference'], 'quantity' => $data['quantity']],
                $wo['reference'] . ' : ' . $data['quantity'] . ' × ' . $part['reference']
            );
        });
    }

    /** Intervenant (personnel du site) et temps passé, cumulé s'il est déjà présent. */
    public function addTechnician(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'personnel_id' => 'required|int',
            'minutes' => 'int|min:0|max:10000',
        ]);
        $this->db->transaction(function () use ($id, $data): void {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            $this->assertCanExecute($wo);
            if (WorkOrderStatus::from($wo['status'])->isClosed() && !($this->auth->user()?->can('workorder.manage') ?? true)) {
                throw Validator::fail('status', 'closed');
            }
            $person = $this->personnel->find($data['personnel_id']);
            if ($person === null || (int) $person['site_id'] !== (int) $wo['site_id'] || !(int) $person['is_active']) {
                throw Validator::fail('personnel_id', 'team_site');
            }
            $this->workOrders->upsertTechnician($id, $data['personnel_id'], $data['minutes'], $this->auth->user()?->id);
            $total = $this->workOrders->technicianMinutes($id);
            if ($total !== null) {
                $this->workOrders->update($id, ['labor_minutes' => $total]);
            }
            $this->logger->log(
                'technician', 'work_order', $id, (int) $wo['site_id'], null,
                ['personnel' => $person['name'], 'minutes' => $data['minutes']],
                $wo['reference'] . ' : ' . $person['name'] . ($data['minutes'] !== null ? ' (' . $data['minutes'] . ' min)' : '')
            );
        });
    }

    public function removeTechnician(int $id, int $technicianId): void
    {
        $this->db->transaction(function () use ($id, $technicianId): void {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            $this->assertCanExecute($wo);
            if (WorkOrderStatus::from($wo['status'])->isClosed()) {
                throw Validator::fail('status', 'closed');
            }
            $removed = null;
            foreach ($this->workOrders->technicians($id) as $technician) {
                if ((int) $technician['id'] === $technicianId) {
                    $removed = $technician;
                }
            }
            if ($this->workOrders->removeTechnician($id, $technicianId) === 0) {
                throw HttpException::notFound();
            }
            $this->workOrders->update($id, ['labor_minutes' => $this->workOrders->technicianMinutes($id)]);
            $this->logger->log(
                'technician_remove', 'work_order', $id, (int) $wo['site_id'],
                ['personnel' => $removed['name'] ?? null, 'minutes' => $removed['minutes'] ?? null], null,
                $wo['reference'] . ' : ' . ($removed['name'] ?? '#' . $technicianId)
            );
        });
    }

    /** Remarque / commentaire de maintenancier (possible aussi après clôture : retour d'expérience). */
    public function addComment(int $id, array $input): int
    {
        $data = Validator::validate($input, ['body' => 'required|string|max:4000']);

        return $this->db->transaction(function () use ($id, $data): int {
            $wo = $this->workOrders->findForUpdate($id) ?? throw HttpException::notFound();
            $commentId = $this->workOrders->addComment($id, $data['body'], $this->auth->user()?->id);
            $this->logger->log('comment', 'work_order', $id, (int) $wo['site_id'], null, ['comment' => mb_substr($data['body'], 0, 200)], $wo['reference']);

            return $commentId;
        });
    }

    /** Un technicien n'agit que sur les OT qui lui sont assignés ou non assignés. */
    public function assertCanExecute(array $wo): void
    {
        $user = $this->auth->user();
        if ($user === null || $user->can('workorder.manage')) {
            return;
        }
        if (!$user->can('workorder.execute')) {
            throw HttpException::forbidden();
        }
        if ($wo['assigned_to'] !== null && (int) $wo['assigned_to'] !== $user->id) {
            throw HttpException::forbidden();
        }
    }

    /** Seuls les responsables assignent librement ; un technicien ne peut s'assigner que lui-même. */
    private function resolveAssignee(?int $assignedTo, int $siteId): ?int
    {
        if ($assignedTo === null) {
            return null;
        }
        $user = $this->auth->user();
        if ($user !== null && !$user->can('workorder.manage')) {
            return $assignedTo === $user->id ? $assignedTo : null;
        }
        $allowed = array_map('intval', array_column($this->users->assignableForSite($siteId), 'id'));
        if (!in_array($assignedTo, $allowed, true)) {
            throw Validator::fail('assigned_to', 'exists');
        }

        return $assignedTo;
    }
}
