<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Role;

final class CurrentUser
{
    /** @param list<int> $siteIds */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly Role $role,
        public readonly string $locale,
        public readonly bool $allSites,
        public readonly array $siteIds,
    ) {
    }

    public function name(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1) . mb_substr($this->lastName, 0, 1));
    }

    public function can(string $permission): bool
    {
        return $this->role->can($permission);
    }

    /** Un administrateur ou un utilisateur « tous sites » voit l'ensemble du parc. */
    public function scope(): SiteScope
    {
        return $this->role === Role::Admin || $this->allSites
            ? SiteScope::all()
            : SiteScope::forSites($this->siteIds);
    }
}
