<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use App\Core\Migrator;
use App\Services\ActivityLogger;
use App\Services\DashboardService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MiscUnitTest extends TestCase
{
    public function testActivityDiffKeepsOnlyChangedFields(): void
    {
        [$before, $after] = ActivityLogger::diff(
            ['id' => 7, 'name' => 'A', 'hour_meter' => 100, 'unit_cost' => '12.50', 'notes' => null, 'updated_at' => 'x'],
            ['name' => 'B', 'hour_meter' => '100', 'unit_cost' => 12.5, 'notes' => '', 'updated_at' => 'y', 'brand' => 'Linde']
        );

        self::assertSame(['name' => 'A'], $before);
        self::assertSame(['name' => 'B', 'brand' => 'Linde'], $after);
    }

    public function testDashboardWeeklySeriesFillsMissingWeeks(): void
    {
        $series = DashboardService::weeklySeries(
            [['yw' => '202638', 'type' => 'corrective', 'n' => 3], ['yw' => 202639, 'type' => 'preventive', 'n' => '2']],
            new DateTimeImmutable('2026-09-24'),
            4
        );

        self::assertSame(['S36', 'S37', 'S38', 'S39'], $series['labels']);
        self::assertSame([0, 0, 3, 0], $series['corrective']);
        self::assertSame([0, 0, 0, 2], $series['preventive']);
    }

    public function testMigrationFilesAreNumberedAndOrdered(): void
    {
        $dir = dirname(__DIR__, 3) . '/database/migrations';
        $files = scandir($dir) ?: [];
        foreach ($files as $file) {
            if ($file[0] !== '.') {
                self::assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.sql$/', $file, "Nom de migration non conforme : $file");
            }
        }
        $sql = array_values(array_filter($files, static fn ($f) => str_ends_with($f, '.sql')));
        $numbers = array_map(static fn ($f) => (int) substr($f, 0, 4), $sql);
        self::assertSame(range(1, count($numbers)), $numbers, 'Numérotation continue sans doublon');
    }

    public function testSqlSplitterIgnoresCommentsAndTrailingSeparators(): void
    {
        $statements = Migrator::splitStatements("-- commentaire\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);\n-- fin\n");
        self::assertSame(['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)'], $statements);
    }

    public function testContainerAutowiresAndSharesInstances(): void
    {
        $c = new Container();
        $a = $c->get(\SplObjectStorage::class);
        self::assertSame($a, $c->get(\SplObjectStorage::class));

        $c->factory('answer', static fn () => new \ArrayObject([42]));
        self::assertSame(42, $c->get('answer')[0]);
    }
}
