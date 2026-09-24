<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Domain\Role;
use App\Security\CurrentUser;
use App\Security\SiteScope;
use PHPUnit\Framework\TestCase;

final class SiteScopeTest extends TestCase
{
    public function testClauseForSiteList(): void
    {
        [$sql, $params] = SiteScope::forSites([3, '7', 3, 0])->clause('e.site_id');

        self::assertSame('e.site_id IN (:scope_0, :scope_1)', $sql);
        self::assertSame(['scope_0' => 3, 'scope_1' => 7], $params);
    }

    public function testGlobalAndEmptyScopes(): void
    {
        self::assertSame(['1=1', []], SiteScope::all()->clause('site_id'));
        self::assertSame(['1=0', []], SiteScope::none()->clause('site_id'), 'Aucun site : aucune ligne visible');
        self::assertSame(['1=0', []], SiteScope::forSites([])->clause('site_id'));
    }

    public function testRejectsNonIdentifierColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteScope::all()->clause('site_id) OR (1=1');
    }

    public function testAllowsAndAssert(): void
    {
        $scope = SiteScope::forSites([1, 2]);
        self::assertTrue($scope->allows(2));
        self::assertFalse($scope->allows(3));
        self::assertFalse($scope->allows(null));
        self::assertTrue(SiteScope::all()->allows(99));

        $this->expectExceptionObject(HttpException::forbidden());
        $scope->assertAllows(3);
    }

    public function testCurrentUserScopeDependsOnRoleAndFlag(): void
    {
        $make = fn (Role $role, bool $all) => new CurrentUser(1, 'a@b.c', 'A', 'B', $role, 'fr', $all, [4]);

        self::assertTrue($make(Role::Admin, false)->scope()->isAll());
        self::assertTrue($make(Role::Manager, true)->scope()->isAll());
        self::assertSame([4], $make(Role::Technician, false)->scope()->siteIds());
    }
}
