<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\EquipmentStatus;
use App\Domain\EquipmentStatusResolver;
use App\Domain\PreventiveSchedule;
use App\Domain\Role;
use App\Domain\WorkOrderStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function testChartColorsAreTheImposedOnes(): void
    {
        self::assertSame('#1E7A4C', EquipmentStatus::Available->color());
        self::assertSame('#B42318', EquipmentStatus::Breakdown->color());
        self::assertSame('#1F4E79', EquipmentStatus::Maintenance->color());
        self::assertSame('#B54708', EquipmentStatus::WaitingPart->color());
        self::assertSame('#8D9098', EquipmentStatus::Immobilized->color());
    }

    public static function transitions(): array
    {
        return [
            ['open', 'in_progress', true],
            ['open', 'done', true],
            ['in_progress', 'waiting_part', true],
            ['waiting_part', 'in_progress', true],
            ['in_progress', 'open', false],
            ['done', 'in_progress', false],
            ['cancelled', 'open', false],
            ['done', 'cancelled', false],
        ];
    }

    #[DataProvider('transitions')]
    public function testWorkOrderLifecycle(string $from, string $to, bool $allowed): void
    {
        self::assertSame($allowed, WorkOrderStatus::from($from)->canTransitionTo(WorkOrderStatus::from($to)));
    }

    public function testWorkOrderImpactOnEquipment(): void
    {
        self::assertSame(EquipmentStatus::Breakdown, WorkOrderStatus::Open->equipmentStatus(true));
        self::assertNull(WorkOrderStatus::Open->equipmentStatus(false));
        self::assertSame(EquipmentStatus::Maintenance, WorkOrderStatus::InProgress->equipmentStatus(false));
        self::assertSame(EquipmentStatus::WaitingPart, WorkOrderStatus::WaitingPart->equipmentStatus(false));
        self::assertNull(WorkOrderStatus::Done->equipmentStatus(true));
    }

    public function testStatusAfterClosureDependsOnRemainingWorkOrders(): void
    {
        $maintenance = EquipmentStatus::Maintenance;
        self::assertSame(EquipmentStatus::Available, EquipmentStatusResolver::afterClosure($maintenance, []));
        self::assertSame(EquipmentStatus::Available, EquipmentStatusResolver::afterClosure($maintenance, [['status' => 'open', 'immobilizing' => 0]]));
        self::assertSame(EquipmentStatus::Breakdown, EquipmentStatusResolver::afterClosure($maintenance, [['status' => 'open', 'immobilizing' => 1]]));
        self::assertSame(EquipmentStatus::WaitingPart, EquipmentStatusResolver::afterClosure($maintenance, [['status' => 'waiting_part', 'immobilizing' => 0], ['status' => 'open', 'immobilizing' => 1]]));
        self::assertSame(EquipmentStatus::Maintenance, EquipmentStatusResolver::afterClosure($maintenance, [['status' => 'waiting_part', 'immobilizing' => 0], ['status' => 'in_progress', 'immobilizing' => 0]]));
    }

    public function testImmobilizedEquipmentIsOnlyChangedManually(): void
    {
        $immobilized = EquipmentStatus::Immobilized;
        self::assertSame($immobilized, EquipmentStatusResolver::afterClosure($immobilized, []));
        self::assertSame($immobilized, EquipmentStatusResolver::onWorkOrderEvent($immobilized, EquipmentStatus::Maintenance));
        self::assertSame(EquipmentStatus::Breakdown, EquipmentStatusResolver::onWorkOrderEvent(EquipmentStatus::Available, EquipmentStatus::Breakdown));
        self::assertSame(EquipmentStatus::Breakdown, EquipmentStatusResolver::onWorkOrderEvent(EquipmentStatus::Breakdown, null));
    }

    public function testPreventiveScheduleByDaysAndHours(): void
    {
        $schedule = new PreventiveSchedule(90, 500);
        self::assertSame('2026-12-23', $schedule->nextDueDate(new DateTimeImmutable('2026-09-24'))?->format('Y-m-d'));
        self::assertSame(1700, $schedule->nextDueHours(1200));

        $hoursOnly = new PreventiveSchedule(null, 250);
        self::assertNull($hoursOnly->nextDueDate(new DateTimeImmutable('2026-09-24')));
        self::assertSame(250, $hoursOnly->nextDueHours(null));
    }

    public function testPreventiveScheduleRequiresAnInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PreventiveSchedule(null, 0);
    }

    public function testPreventiveDueDetection(): void
    {
        $today = new DateTimeImmutable('2026-09-24');
        self::assertTrue(PreventiveSchedule::isDue('2026-09-24', null, $today, null));
        self::assertFalse(PreventiveSchedule::isDue('2026-10-01', null, $today, null));
        self::assertTrue(PreventiveSchedule::isDue('2026-10-01', null, $today, null, 7), 'Dans l\'horizon');
        self::assertTrue(PreventiveSchedule::isDue('2027-01-01', 1500, $today, 1500), 'Compteur atteint');
        self::assertFalse(PreventiveSchedule::isDue(null, 1500, $today, 1499));
    }

    public function testRolePermissions(): void
    {
        foreach (Role::permissions() as $permission) {
            self::assertTrue(Role::Admin->can($permission), "admin doit avoir $permission");
        }
        self::assertTrue(Role::Technician->can('workorder.execute'));
        self::assertFalse(Role::Technician->can('workorder.manage'));
        self::assertFalse(Role::Technician->can('equipment.manage'));
        self::assertFalse(Role::Manager->can('users.manage'));
        self::assertTrue(Role::Manager->can('activity.view'));
        self::assertFalse(Role::Viewer->can('workorder.create'));
        self::assertTrue(Role::Viewer->can('equipment.view'));
        self::assertFalse(Role::Viewer->can('unknown.permission'));
    }
}
