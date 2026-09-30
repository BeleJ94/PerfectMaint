<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\Workforce;
use App\Repositories\AssignmentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\TeamRepository;
use App\Security\Auth;

/** Personnel, équipes par shift et affectations aux engins (transactionnel, journalisé). */
final class WorkforceService
{
    public function __construct(
        private readonly Database $db,
        private readonly PersonnelRepository $personnel,
        private readonly TeamRepository $teams,
        private readonly AssignmentRepository $assignments,
        private readonly EquipmentRepository $equipment,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    // ---------------------------------------------------------------- Personnel

    private static function personRules(): array
    {
        return [
            'site_id' => 'required|int',
            'team_id' => 'int',
            'employee_no' => 'string|max:30',
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'job' => 'required|in:' . implode(',', Workforce::JOBS),
            'phone' => 'string|max:30',
            'license_number' => 'string|max:40',
            'notes' => 'string|max:255',
            'is_active' => 'bool',
        ];
    }

    public function createPerson(array $input): int
    {
        $data = $this->validatePerson($input, null);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->personnel->create($data);
            $this->logger->log('create', 'personnel', $id, $data['site_id'], null, $data, $data['first_name'] . ' ' . $data['last_name']);

            return $id;
        });
    }

    public function updatePerson(int $id, array $input): void
    {
        $data = $this->validatePerson($input, $id);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->personnel->find($id) ?? throw HttpException::notFound();
            $this->personnel->update($id, $data);
            // Une personne désactivée ou mutée n'est plus en poste sur les engins de son ancien site.
            if (!$data['is_active'] || (int) $before['site_id'] !== $data['site_id']) {
                foreach ($this->assignments->openConflicts(0, $id, '') as $open) {
                    $this->assignments->close((int) $open['id']);
                }
            }
            $this->logger->log('update', 'personnel', $id, $data['site_id'], $before, $this->personnel->find($id), $data['first_name'] . ' ' . $data['last_name']);
        });
    }

    private function validatePerson(array $input, ?int $id): array
    {
        $data = Validator::validate($input, self::personRules());
        $this->personnel->scope()->assertAllows($data['site_id']);
        if ($data['employee_no'] !== null) {
            $data['employee_no'] = mb_strtoupper($data['employee_no']);
            if ($this->personnel->employeeNoExists($data['employee_no'], $id)) {
                throw Validator::fail('employee_no', 'unique');
            }
        }
        if ($data['team_id'] !== null) {
            $team = $this->teams->find($data['team_id']);
            if ($team === null || (int) $team['site_id'] !== $data['site_id']) {
                throw Validator::fail('team_id', 'team_site');
            }
        }
        $data['first_name'] = mb_convert_case($data['first_name'], MB_CASE_TITLE);
        $data['last_name'] = mb_strtoupper($data['last_name']);

        return $data;
    }

    // ---------------------------------------------------------------- Équipes

    private static function teamRules(): array
    {
        return [
            'site_id' => 'required|int',
            'name' => 'required|string|max:80',
            'kind' => 'required|in:' . implode(',', Workforce::TEAM_KINDS),
            'shift' => 'required|in:' . implode(',', Workforce::SHIFTS),
            'leader_id' => 'int',
            'dispatch_name' => 'string|max:120',
            'dispatch_phone' => 'string|max:30',
            'is_active' => 'bool',
        ];
    }

    public function createTeam(array $input): int
    {
        $data = $this->validateTeam($input, null);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->teams->create($data);
            $this->joinLeader($id, $data);
            $this->logger->log('create', 'team', $id, $data['site_id'], null, $data, $data['name']);

            return $id;
        });
    }

    public function updateTeam(int $id, array $input): void
    {
        $data = $this->validateTeam($input, $id);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->teams->find($id) ?? throw HttpException::notFound();
            $this->teams->update($id, $data);
            $this->joinLeader($id, $data);
            $this->logger->log('update', 'team', $id, $data['site_id'], $before, $this->teams->find($id), $data['name']);
        });
    }

    private function validateTeam(array $input, ?int $id): array
    {
        $data = Validator::validate($input, self::teamRules());
        $this->teams->scope()->assertAllows($data['site_id']);
        if ($this->teams->nameExists($data['site_id'], $data['name'], $id)) {
            throw Validator::fail('name', 'unique');
        }
        if ($data['leader_id'] !== null) {
            $leader = $this->personnel->find($data['leader_id']);
            if ($leader === null || (int) $leader['site_id'] !== $data['site_id']) {
                throw Validator::fail('leader_id', 'team_site');
            }
        }

        return $data;
    }

    /** Le chef d'équipe fait partie de son équipe. */
    private function joinLeader(int $teamId, array $data): void
    {
        if ($data['leader_id'] !== null) {
            $this->db->execute('UPDATE personnel SET team_id = :t WHERE id = :p', ['t' => $teamId, 'p' => $data['leader_id']]);
        }
    }

    // ---------------------------------------------------------------- Affectations

    /** Affecte un chauffeur / opérateur à un engin pour un shift ; clôt les affectations incompatibles. */
    public function assign(int $equipmentId, array $input): int
    {
        $data = Validator::validate($input, [
            'personnel_id' => 'required|int',
            'shift' => 'required|in:' . implode(',', Workforce::SHIFTS),
            'note' => 'string|max:255',
        ]);

        return $this->db->transaction(function () use ($equipmentId, $data): int {
            $equipment = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $person = $this->personnel->find($data['personnel_id']);
            if ($person === null || (int) $person['site_id'] !== (int) $equipment['site_id'] || !(int) $person['is_active']) {
                throw Validator::fail('personnel_id', 'team_site');
            }
            if (!in_array($person['job'], Workforce::OPERATOR_JOBS, true)) {
                throw Validator::fail('personnel_id', 'not_operator');
            }
            foreach ($this->assignments->openConflicts($equipmentId, $data['personnel_id'], $data['shift']) as $open) {
                $this->assignments->close((int) $open['id']);
            }
            $id = $this->assignments->create($equipmentId, $data['personnel_id'], $data['shift'], $data['note'], $this->auth->user()?->id);
            $this->logger->log(
                'assign', 'equipment', $equipmentId, (int) $equipment['site_id'], null,
                ['personnel' => $person['name'], 'shift' => $data['shift']],
                $equipment['code'] . ' ← ' . $person['name']
            );

            return $id;
        });
    }

    public function unassign(int $equipmentId, int $assignmentId): void
    {
        $this->db->transaction(function () use ($equipmentId, $assignmentId): void {
            $assignment = $this->assignments->find($assignmentId);
            if ($assignment === null || (int) $assignment['equipment_id'] !== $equipmentId) {
                throw HttpException::notFound();
            }
            $this->assignments->close($assignmentId);
            $this->logger->log(
                'unassign', 'equipment', $equipmentId, (int) $assignment['site_id'],
                ['personnel' => $assignment['name'], 'shift' => $assignment['shift']], null,
                $assignment['equipment_code'] . ' ✕ ' . $assignment['name']
            );
        });
    }
}
