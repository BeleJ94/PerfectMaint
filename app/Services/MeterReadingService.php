<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentRepository;
use App\Repositories\FuelGaugeRepository;
use App\Repositories\MeterReadingRepository;
use App\Security\Auth;

/**
 * Relevés d'index hors saisie du jour :
 * - rattrapage d'un jour passé par l'encodeur, uniquement si l'engin n'a aucun relevé ce jour-là ;
 * - répertoire de l'administrateur : modification et suppression de n'importe quel relevé.
 * Un relevé doit rester entre les index connus avant et après lui ; les compteurs de l'engin
 * suivent toujours son relevé le plus récent.
 */
final class MeterReadingService
{
    /** Heure attribuée à un relevé rattrapé (la saisie ne porte que sur la journée). */
    public const BACKFILL_TIME = '12:00:00';

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly MeterReadingRepository $readings,
        private readonly FuelGaugeRepository $gauges,
        private readonly MeterService $meters,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** Jour passé saisissable : strictement avant aujourd'hui. */
    public static function pastDay(mixed $value): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || $value >= date('Y-m-d')) {
            throw Validator::fail('date', 'reading_past_day');
        }

        return $value;
    }

    /** Écart anormal avec le relevé précédant ce jour (null si plausible), comme pour la saisie du jour. */
    public function backfillWarning(int $equipmentId, string $date, array $input): ?string
    {
        $data = self::validateIndex($input);
        $bounds = $this->readings->bounds($equipmentId, $date . ' ' . self::BACKFILL_TIME);
        if ($bounds['prev_at'] === null) {
            return null;
        }
        $days = max(1, (int) (new \DateTimeImmutable(substr($bounds['prev_at'], 0, 10)))->diff(new \DateTimeImmutable($date))->format('%a'));
        if ($data['hour_meter'] !== null && $bounds['prev_hours'] !== null
            && $data['hour_meter'] - $bounds['prev_hours'] > MeterEntryService::MAX_HOURS_PER_DAY * $days) {
            return t('entry.warn_hours', ['delta' => format_number($data['hour_meter'] - $bounds['prev_hours']), 'days' => $days]);
        }
        if ($data['odometer_km'] !== null && $bounds['prev_km'] !== null
            && $data['odometer_km'] - $bounds['prev_km'] > MeterEntryService::MAX_KM_PER_DAY * $days) {
            return t('entry.warn_km', ['delta' => format_number($data['odometer_km'] - $bounds['prev_km']), 'days' => $days]);
        }

        return null;
    }

    /** Rattrapage : relevé d'un jour passé pour un engin qui n'en a aucun ce jour-là. */
    public function backfill(int $equipmentId, string $date, array $input): int
    {
        $data = self::validateIndex($input);

        return $this->db->transaction(function () use ($equipmentId, $date, $data): int {
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            if ($this->readings->existsOnDay($equipmentId, $date)) {
                throw Validator::fail('date', 'reading_day_taken', ['date' => format_date($date)]);
            }
            $at = $date . ' ' . self::BACKFILL_TIME;
            $this->checkBounds($this->readings->bounds($equipmentId, $at), $data['hour_meter'], $data['odometer_km']);
            $fuel = $this->meters->fuel($equipmentId, $data);
            $id = $this->readings->insert(
                $equipmentId, $data['hour_meter'], $data['odometer_km'], $fuel['fuel_level'] ?? null, $data['note'], $this->auth->user()?->id, $at
            );
            if ($fuel !== null) {
                $this->gauges->saveReadingDetails($id, $fuel['fuel_mark'], $fuel['fuel_marks'], $fuel['fuel_liters']);
            }
            // Engin jamais relevé depuis : ce relevé devient son index courant.
            if ($this->readings->isLatest($equipmentId, $at)) {
                $this->readings->syncEquipment($equipmentId);
            }
            $this->logger->log('reading_backfill', 'equipment', $equipmentId, (int) $row['site_id'], null,
                array_filter(['date' => $date, 'hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km'],
                    'fuel_level' => $fuel['fuel_level'] ?? null], static fn ($v) => $v !== null),
                $row['code']);

            return $id;
        });
    }

    /** Modification d'un relevé par l'administrateur (date, index, carburant, remarque). */
    public function update(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'recorded_at' => 'required|datetime',
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'fuel_level' => 'int|min:0|max:100',
            'note' => 'string|max:255',
        ]);
        if ($data['hour_meter'] === null && $data['odometer_km'] === null) {
            throw Validator::fail('hour_meter', 'index_required');
        }
        if ($data['recorded_at'] > date('Y-m-d H:i:s')) {
            throw Validator::fail('recorded_at', 'reading_future');
        }

        $this->db->transaction(function () use ($id, $data): void {
            $reading = $this->readings->find($id) ?? throw HttpException::notFound();
            $equipmentId = (int) $reading['equipment_id'];
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $day = substr($data['recorded_at'], 0, 10);
            if ($day !== substr((string) $reading['recorded_at'], 0, 10) && $this->readings->existsOnDay($equipmentId, $day, $id)) {
                throw Validator::fail('recorded_at', 'reading_day_taken', ['date' => format_date($day)]);
            }
            $this->checkBounds($this->readings->bounds($equipmentId, $data['recorded_at'], $id), $data['hour_meter'], $data['odometer_km']);

            $this->readings->update($id, $data['hour_meter'], $data['odometer_km'], $data['fuel_level'], $data['note'], $data['recorded_at']);
            // Niveau modifié : la graduation lue n'est plus valable, les litres sont recalculés.
            $oldFuel = $reading['fuel_level'] !== null ? (int) $reading['fuel_level'] : null;
            if ($data['fuel_level'] !== $oldFuel) {
                $liters = $data['fuel_level'] !== null ? $this->gauges->gaugeFor($equipmentId)->fromPercent($data['fuel_level'])['fuel_liters'] : null;
                $this->gauges->saveReadingDetails($id, null, null, $liters);
            }
            $this->readings->syncEquipment($equipmentId);

            $keys = ['recorded_at', 'hour_meter', 'odometer_km', 'fuel_level', 'note'];
            $this->logger->log('reading_update', 'equipment', $equipmentId, (int) $row['site_id'],
                array_intersect_key($reading, array_flip($keys)), array_intersect_key($data, array_flip($keys)),
                $row['code'] . ' · ' . format_date(substr((string) $reading['recorded_at'], 0, 10)));
        });
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $reading = $this->readings->find($id) ?? throw HttpException::notFound();
            $equipmentId = (int) $reading['equipment_id'];
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $this->readings->delete($id);
            $this->readings->syncEquipment($equipmentId);
            $this->logger->log('reading_delete', 'equipment', $equipmentId, (int) $row['site_id'],
                array_filter(array_intersect_key($reading, array_flip(['recorded_at', 'hour_meter', 'odometer_km', 'fuel_level'])),
                    static fn ($v) => $v !== null),
                null, $row['code'] . ' · ' . format_date(substr((string) $reading['recorded_at'], 0, 10)));
        });
    }

    /** Les compteurs ne reculent pas : la valeur reste entre l'index précédent et l'index suivant. */
    private function checkBounds(array $bounds, ?int $hours, ?int $km): void
    {
        foreach (['hour_meter' => [$hours, 'prev_hours', 'next_hours'], 'odometer_km' => [$km, 'prev_km', 'next_km']] as $field => [$value, $prev, $next]) {
            if ($value === null) {
                continue;
            }
            if ($bounds[$prev] !== null && $value < $bounds[$prev]) {
                throw Validator::fail($field, 'meter_decrease', ['current' => format_number($bounds[$prev])]);
            }
            if ($bounds[$next] !== null && $value > $bounds[$next]) {
                throw Validator::fail($field, 'meter_above_next', ['next' => format_number($bounds[$next])]);
            }
        }
    }

    private static function validateIndex(array $input): array
    {
        $data = Validator::validate($input, [
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'fuel_level' => 'int|min:0|max:100',
            'fuel_mark' => 'numeric|min:0|max:20',
            'note' => 'string|max:255',
        ]);
        if ($data['hour_meter'] === null && $data['odometer_km'] === null) {
            throw Validator::fail('hour_meter', 'index_required');
        }

        return $data;
    }
}
