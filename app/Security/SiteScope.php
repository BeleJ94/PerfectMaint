<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\HttpException;

/**
 * Périmètre de sites visibles par l'utilisateur courant.
 * Injecté dans chaque repository : toute requête sur une donnée rattachée à un site
 * DOIT inclure la clause produite par clause().
 */
final class SiteScope
{
    /** @param list<int> $siteIds */
    private function __construct(private readonly bool $all, private readonly array $siteIds)
    {
    }

    public static function all(): self
    {
        return new self(true, []);
    }

    /** @param iterable<int|string> $siteIds */
    public static function forSites(iterable $siteIds): self
    {
        $ids = [];
        foreach ($siteIds as $id) {
            $ids[] = (int) $id;
        }

        return new self(false, array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0))));
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    /** @return list<int> */
    public function siteIds(): array
    {
        return $this->siteIds;
    }

    public function allows(?int $siteId): bool
    {
        return $siteId !== null && ($this->all || in_array($siteId, $this->siteIds, true));
    }

    public function assertAllows(?int $siteId): void
    {
        if (!$this->allows($siteId)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Fragment SQL + paramètres nommés limitant $column au périmètre.
     * $column est un identifiant fixé dans le code (jamais une saisie utilisateur).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function clause(string $column, string $prefix = 'scope'): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
            throw new \InvalidArgumentException("Colonne de périmètre invalide : $column");
        }
        if ($this->all) {
            return ['1=1', []];
        }
        if ($this->siteIds === []) {
            return ['1=0', []];
        }
        $placeholders = [];
        $params = [];
        foreach ($this->siteIds as $i => $id) {
            $placeholders[] = ":{$prefix}_$i";
            $params["{$prefix}_$i"] = $id;
        }

        return ["$column IN (" . implode(', ', $placeholders) . ')', $params];
    }
}
