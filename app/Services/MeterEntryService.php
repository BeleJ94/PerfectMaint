<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentRepository;
use App\Repositories\MeterEntryRepository;
use App\Security\Auth;

/**
 * Saisie quotidienne des index par les encodeurs : feuille des engins à relever, contrôle des écarts
 * anormaux et correction, le jour même, de sa propre saisie.
 */
final class MeterEntryService
{
    /** Au-delà, la saisie est probablement une faute de frappe : confirmation demandée. */
    public const MAX_HOURS_PER_DAY = 24;
    public const MAX_KM_PER_DAY = 800;

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly MeterEntryRepository $entries,
        private readonly MeterService $meters,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @return array{todo: list<array>, done: list<array>, total: int}
     *   todo = engins sans relevé aujourd'hui, done = engins déjà relevés (les plus récents d'abord)
     */
    public function sheet(?int $siteId): array
    {
        $todo = [];
        $done = [];
        foreach ($this->entries->sheet($siteId) as $row) {
            $row = $this->decorate($row);
            if ($row['read_today']) {
                $done[] = $row;
            } else {
                $todo[] = $row;
            }
        }
        usort($done, static fn (array $a, array $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        return ['todo' => $todo, 'done' => $done, 'total' => count($todo) + count($done)];
    }

    /** Ligne de la feuille pour un engin (404 hors périmètre). */
    public function row(int $equipmentId): array
    {
        $rows = $this->entries->sheet(null, $equipmentId);

        return $this->decorate($rows[0] ?? throw HttpException::notFound());
    }

    /**
     * Écart anormal par rapport au relevé précédent (null si la saisie est plausible).
     * La valeur reste acceptée si l'agent la confirme : un engin peut ne pas avoir été relevé depuis longtemps.
     */
    public function warning(int $equipmentId, array $input): ?string
    {
        $data = self::validate($input);
        [$base] = $this->reference($equipmentId);
        if ($base === null) {
            return null;
        }
        $days = max(1, (int) (new \DateTimeImmutable(substr($base['recorded_at'], 0, 10)))->diff(new \DateTimeImmutable('today'))->format('%a'));
        if ($data['hour_meter'] !== null && $base['hour_meter'] !== null
            && $data['hour_meter'] - (int) $base['hour_meter'] > self::MAX_HOURS_PER_DAY * $days) {
            return t('entry.warn_hours', ['delta' => format_number($data['hour_meter'] - (int) $base['hour_meter']), 'days' => $days]);
        }
        if ($data['odometer_km'] !== null && $base['odometer_km'] !== null
            && $data['odometer_km'] - (int) $base['odometer_km'] > self::MAX_KM_PER_DAY * $days) {
            return t('entry.warn_km', ['delta' => format_number($data['odometer_km'] - (int) $base['odometer_km']), 'days' => $days]);
        }

        return null;
    }

    /** Enregistre un relevé, ou corrige celui que l'agent a lui-même saisi aujourd'hui. */
    public function save(int $equipmentId, array $input): void
    {
        $data = self::validate($input);
        if ($data['hour_meter'] === null && $data['odometer_km'] === null) {
            throw Validator::fail('hour_meter', 'index_required');
        }

        $this->db->transaction(function () use ($equipmentId, $data): void {
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            [$base, $own] = $this->reference($equipmentId);
            if ($own === null) {
                $this->meters->record($equipmentId, $data);

                return;
            }

            // Correction : la nouvelle valeur remplace la saisie du jour ; elle ne peut pas passer sous le relevé précédent.
            $hours = $data['hour_meter'] ?? ($own['hour_meter'] !== null ? (int) $own['hour_meter'] : null);
            $km = $data['odometer_km'] ?? ($own['odometer_km'] !== null ? (int) $own['odometer_km'] : null);
            foreach (['hour_meter' => $hours, 'odometer_km' => $km] as $counter => $value) {
                if ($value !== null && $base !== null && $base[$counter] !== null && $value < (int) $base[$counter]) {
                    throw Validator::fail($counter, 'meter_decrease', ['current' => format_number($base[$counter])]);
                }
            }
            $this->entries->updateReading((int) $own['id'], $hours, $km, $data['fuel_level'], $data['note']);
            $this->entries->setMeters($equipmentId, $hours ?? ($row['hour_meter'] !== null ? (int) $row['hour_meter'] : null),
                $km ?? ($row['odometer_km'] !== null ? (int) $row['odometer_km'] : null), $data['fuel_level']);
            $this->logger->log(
                'reading', 'equipment', $equipmentId, (int) $row['site_id'],
                ['hour_meter' => $row['hour_meter'], 'odometer_km' => $row['odometer_km']],
                ['hour_meter' => $hours, 'odometer_km' => $km],
                $row['code']
            );
        });
    }

    private static function validate(array $input): array
    {
        return Validator::validate($input, [
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'fuel_level' => 'int|min:0|max:100',
            'note' => 'string|max:255',
        ]);
    }

    /**
     * Relevé de référence pour les contrôles, et relevé du jour à corriger s'il appartient à l'agent.
     *
     * @return array{0: ?array, 1: ?array} [relevé précédent, propre relevé du jour]
     */
    private function reference(int $equipmentId): array
    {
        $last = $this->entries->lastTwo($equipmentId);
        if ($last === []) {
            return [null, null];
        }
        $userId = $this->auth->user()?->id;
        $own = $userId !== null && (int) $last[0]['recorded_by'] === $userId && str_starts_with((string) $last[0]['recorded_at'], date('Y-m-d'));

        return $own ? [$last[1] ?? null, $last[0]] : [$last[0], null];
    }

    private function decorate(array $row): array
    {
        $today = date('Y-m-d');
        $row['read_today'] = $row['last_at'] !== null && str_starts_with((string) $row['last_at'], $today);
        $row['own'] = $row['read_today'] && $row['last_by'] !== null && (int) $row['last_by'] === $this->auth->user()?->id;
        // Le kilométrage n'est demandé que pour les engins qui ont un compteur kilométrique (ou pas de compteur horaire).
        $row['has_km'] = $row['odometer_km'] !== null || $row['hour_meter'] === null;
        $row['label'] = trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')) ?: $row['name'];

        return $row;
    }
}
