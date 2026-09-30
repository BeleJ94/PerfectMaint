<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\PreventiveSchedule;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use DateTimeImmutable;

final class PreventiveService
{
    public const DEFAULT_HORIZON_DAYS = 7;

    public function __construct(
        private readonly Database $db,
        private readonly PreventivePlanRepository $plans,
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderService $workOrders,
        private readonly ActivityLogger $logger,
    ) {
    }

    private static function rules(): array
    {
        return [
            'equipment_id' => 'required|int',
            'title' => 'required|string|max:150',
            'instructions' => 'string|max:5000',
            'interval_days' => 'int|min:1|max:3650',
            'interval_hours' => 'int|min:1|max:100000',
            'last_done_at' => 'date',
            'last_done_hours' => 'int|min:0|max:999999',
            'next_due_date' => 'date',
            'is_active' => 'bool',
        ];
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->plans->create($data);
            $this->logger->log('create', 'preventive_plan', $id, $data['site_id'], null, $data, $data['title']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = $this->prepare($input);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->plans->findForUpdate($id) ?? throw HttpException::notFound();
            $this->plans->update($id, $data);
            $this->logger->log('update', 'preventive_plan', $id, $data['site_id'], $before, $data, $data['title']);
        });
    }

    /** Valide et calcule les prochaines échéances à partir de la dernière exécution connue. */
    private function prepare(array $input): array
    {
        $data = Validator::validate($input, self::rules());
        if ($data['interval_days'] === null && $data['interval_hours'] === null) {
            throw Validator::fail('interval_days', 'interval');
        }
        $equipment = $this->equipment->find($data['equipment_id']) ?? throw Validator::fail('equipment_id', 'exists');
        $data['site_id'] = (int) $equipment['site_id'];

        $schedule = new PreventiveSchedule($data['interval_days'], $data['interval_hours']);
        $reference = new DateTimeImmutable($data['last_done_at'] ?? 'today');
        $data['next_due_date'] ??= $schedule->nextDueDate($reference)?->format('Y-m-d');
        if ($data['interval_days'] === null) {
            $data['next_due_date'] = null;
        }
        $data['next_due_hours'] = $schedule->nextDueHours(
            $data['last_done_hours'] ?? ($equipment['hour_meter'] !== null ? (int) $equipment['hour_meter'] : 0)
        );

        return $data;
    }

    /**
     * Crée un OT préventif pour chaque plan à échéance (sans OT déjà ouvert).
     *
     * @return list<int> identifiants des OT créés
     */
    public function generateDue(int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $created = [];
        foreach ($this->plans->dueWithoutOpenWorkOrder($horizonDays) as $plan) {
            $created[] = $this->workOrders->createFromPlan($plan);
        }

        return $created;
    }
}
