<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Repositories\ActivityLogRepository;
use App\Services\ActivityLogger;
use Tests\DatabaseTestCase;

/** Journal d'activité : filtres « qui / quoi », synthèse par utilisateur, respect du périmètre. */
final class ActivityLogTest extends DatabaseTestCase
{
    private int $lyon;
    private int $lille;
    private int $admin;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lyon = $this->createSite('LYO');
        $this->lille = $this->createSite('LIL');
        $this->admin = $this->createUser('admin');
        $this->manager = $this->createUser('manager', [$this->lyon]);

        $this->service($this->admin, ActivityLogger::class)->log('create', 'equipment', 1, $this->lyon, null, ['code' => 'A'], 'A');
        $asManager = $this->service($this->manager, ActivityLogger::class);
        $asManager->log('update', 'equipment', 1, $this->lyon, ['name' => 'a'], ['name' => 'b'], 'A');
        $asManager->log('create', 'work_order', 1, $this->lille, null, ['title' => 'Fuite'], 'OT-1');
        $this->service(null, ActivityLogger::class)->log('create', 'work_order', 2, $this->lyon, null, ['title' => 'Révision'], 'OT-2');
    }

    private function entries(int $asUser, array $filters): int
    {
        $dt = DataTableRequest::fromQuery([], ActivityLogRepository::SORTABLE, ['l.id', 'DESC']);

        return $this->service($asUser, ActivityLogRepository::class)->datatable($dt, $filters)['recordsFiltered'];
    }

    public function testListIsFilteredByUserAndAction(): void
    {
        self::assertSame(4, $this->entries($this->admin, []));
        self::assertSame(2, $this->entries($this->admin, ['user_id' => $this->manager]));
        self::assertSame(1, $this->entries($this->admin, ['user_id' => $this->manager, 'action' => 'update']));
        self::assertSame(3, $this->entries($this->admin, ['action' => 'create']));
        self::assertSame(1, $this->entries($this->admin, ['user_id' => ActivityLogRepository::NO_USER]), 'Entrées système');
        self::assertSame(0, $this->entries($this->admin, ['from' => '2000-01-01', 'to' => '2000-01-02']));
    }

    public function testSummaryCountsActionsPerUser(): void
    {
        $repo = $this->service($this->admin, ActivityLogRepository::class);
        $summary = $repo->byUser([]);

        self::assertSame($this->manager, (int) $summary[0]['user_id'], 'Le plus actif en premier');
        self::assertSame(2, (int) $summary[0]['actions']);
        self::assertSame('Manager Test', $summary[0]['user_name']);
        self::assertSame('manager', $summary[0]['role']);
        self::assertCount(3, $summary);
        self::assertContains(null, array_column($summary, 'user_id'), 'Les entrées système forment un groupe');

        self::assertSame($summary, $repo->byUser(['user_id' => $this->admin]), 'Le filtre utilisateur ne restreint pas la synthèse');
        $updates = $repo->byUser(['action' => 'update']);
        self::assertSame([$this->manager], array_map('intval', array_column($updates, 'user_id')));
    }

    public function testSummaryAndActorsRespectSiteScope(): void
    {
        $repo = $this->service($this->manager, ActivityLogRepository::class);

        $byUser = array_column($repo->byUser([]), 'actions', 'user_id');
        self::assertSame(1, (int) $byUser[$this->manager], "L'action sur le site hors périmètre n'est pas comptée");
        self::assertSame(3, $this->entries($this->manager, []));
        self::assertEqualsCanonicalizing([$this->admin, $this->manager], array_map('intval', array_column($repo->actors(), 'id')));

        $techLille = $this->createUser('technician', [$this->lille]);
        $actors = $this->service($techLille, ActivityLogRepository::class)->actors();
        self::assertSame([$this->manager], array_map('intval', array_column($actors, 'id')), 'Seuls les auteurs visibles dans le périmètre sont proposés');
    }
}
