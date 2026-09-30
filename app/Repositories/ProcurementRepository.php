<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * Besoins d'achat et de magasin : pièces d'usure, intrants et consommables par site,
 * avec consommation récente, couverture et quantité à commander suggérée.
 *
 * Règles : consommation mensuelle = sorties des 90 derniers jours / 3 ;
 * stock cible = max(2 × seuil mini, 2 mois de consommation) ; suggestion = cible − stock (arrondi au supérieur).
 * Niveau : rupture (stock nul), sous seuil, à surveiller (moins d'un mois de couverture), OK.
 */
final class ProcurementRepository extends Repository
{
    public const WINDOW_DAYS = 90;
    public const LEVELS = ['out', 'low', 'watch', 'ok'];

    public const SORTABLE = [
        'reference' => 'x.reference',
        'name' => 'x.name',
        'part_type' => 'x.part_type',
        'site' => 'x.site',
        'quantity' => 'x.quantity',
        'monthly' => 'x.monthly',
        'coverage_days' => 'x.coverage_days',
        'level' => 'x.level_rank',
        'suggested' => 'x.suggested',
        'order_value' => 'x.order_value',
    ];

    private function source(): array
    {
        [$scopeSql, $params] = $this->scope->clause('ps.site_id');
        $sql = "(SELECT p.id, p.reference, p.name, p.part_type, p.manufacturer, p.unit, p.unit_cost, p.is_wear_part,
                        s.id AS site_id, s.name AS site, ps.quantity, ps.min_quantity, ps.location,
                        COALESCE(c.qty, 0) AS consumed,
                        ROUND(COALESCE(c.qty, 0) / 3, 2) AS monthly,
                        CASE WHEN COALESCE(c.qty, 0) > 0 THEN FLOOR(ps.quantity / (c.qty / " . self::WINDOW_DAYS . ")) END AS coverage_days,
                        CASE WHEN ps.quantity <= 0 THEN 'out'
                             WHEN ps.quantity < ps.min_quantity THEN 'low'
                             WHEN COALESCE(c.qty, 0) > 0 AND ps.quantity < c.qty / 3 THEN 'watch'
                             ELSE 'ok' END AS level,
                        CASE WHEN ps.quantity <= 0 THEN 0 WHEN ps.quantity < ps.min_quantity THEN 1
                             WHEN COALESCE(c.qty, 0) > 0 AND ps.quantity < c.qty / 3 THEN 2 ELSE 3 END AS level_rank,
                        GREATEST(0, CEIL(GREATEST(ps.min_quantity * 2, COALESCE(c.qty, 0) / 3 * 2) - ps.quantity)) AS suggested,
                        GREATEST(0, CEIL(GREATEST(ps.min_quantity * 2, COALESCE(c.qty, 0) / 3 * 2) - ps.quantity)) * p.unit_cost AS order_value
                   FROM part_stocks ps
                   JOIN parts p ON p.id = ps.part_id AND p.is_active = 1
                   JOIN sites s ON s.id = ps.site_id AND s.is_active = 1
                   LEFT JOIN (SELECT part_id, site_id, SUM(quantity) AS qty FROM stock_movements
                               WHERE type = 'out' AND created_at >= NOW() - INTERVAL " . self::WINDOW_DAYS . " DAY
                               GROUP BY part_id, site_id) c ON c.part_id = ps.part_id AND c.site_id = ps.site_id
                  WHERE $scopeSql
                    AND (p.is_wear_part = 1 OR p.part_type <> 'part' OR ps.quantity < ps.min_quantity OR c.qty > 0)
                ) x";

        return [$sql, $params];
    }

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$from, $params] = $this->source();
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'x.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['part_type'])) {
            $where[] = 'x.part_type = :f_type';
            $fp['f_type'] = (string) $filters['part_type'];
        }
        if (!empty($filters['level'])) {
            $where[] = $filters['level'] === 'action' ? "x.level <> 'ok'" : 'x.level = :f_level';
            if ($filters['level'] !== 'action') {
                $fp['f_level'] = (string) $filters['level'];
            }
        }

        return $this->paginate(
            $dt,
            'x.*',
            $from,
            ['1=1'],
            $params,
            $where,
            $fp,
            ['x.reference', 'x.name', 'x.manufacturer'],
        );
    }

    /** Synthèse : nombre de lignes par niveau et valeur de la commande suggérée. */
    public function summary(?int $siteId = null): array
    {
        [$from, $params] = $this->source();
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' WHERE x.site_id = :site';
            $params['site'] = $siteId;
        }
        $row = $this->db->fetchOne(
            "SELECT SUM(x.level = 'out') AS out_count, SUM(x.level = 'low') AS low_count, SUM(x.level = 'watch') AS watch_count,
                    COUNT(*) AS total, SUM(x.order_value) AS order_value
               FROM $from $siteSql",
            $params
        ) ?? [];

        return array_map(static fn ($v) => $v === null ? 0 : $v, $row);
    }

    /** Valeur consommée (sorties de stock) sur $days jours, par site. */
    public function consumptionValueBySite(int $days = 30): array
    {
        [$scopeSql, $params] = $this->scope->clause('m.site_id');

        return $this->db->fetchAll(
            "SELECT s.id AS site_id, s.name AS site, SUM(m.quantity * p.unit_cost) AS value, COUNT(*) AS movements
               FROM stock_movements m JOIN parts p ON p.id = m.part_id JOIN sites s ON s.id = m.site_id AND s.is_active = 1
              WHERE m.type = 'out' AND m.created_at >= NOW() - INTERVAL :days DAY AND $scopeSql
              GROUP BY s.id ORDER BY value DESC",
            ['days' => $days] + $params
        );
    }

    /** Articles les plus consommés (valeur) sur $days jours. */
    public function topConsumed(int $days = 30, ?int $siteId = null, int $limit = 6): array
    {
        [$scopeSql, $params] = $this->scope->clause('m.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND m.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT p.id, p.reference, p.name, p.part_type, p.unit, SUM(m.quantity) AS quantity, SUM(m.quantity * p.unit_cost) AS value
               FROM stock_movements m JOIN parts p ON p.id = m.part_id JOIN sites s ON s.id = m.site_id AND s.is_active = 1
              WHERE m.type = 'out' AND m.created_at >= NOW() - INTERVAL :days DAY AND $scopeSql $siteSql
              GROUP BY p.id ORDER BY value DESC LIMIT " . max(1, $limit),
            ['days' => $days] + $params
        );
    }
}
