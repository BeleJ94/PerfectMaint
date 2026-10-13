<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Projection d'une échéance au compteur (heures ou km) à partir des relevés d'index :
 * rythme moyen d'utilisation, reste avant échéance, date prévue et statut.
 */
final class MeterForecast
{
    /** Fenêtre de calcul du rythme d'utilisation. */
    public const WINDOW_DAYS = 30;
    /** Part de l'intervalle consommée à partir de laquelle l'échéance est « à préparer ». */
    public const ALERT_RATIO = 0.9;
    /** Échéance prévue dans ce délai : « à préparer ». */
    public const ALERT_DAYS = 7;
    /** Les index sont relevés chaque jour : au-delà de ce délai sans relevé, l'index n'est plus à jour. */
    public const STALE_DAYS = 2;

    public const OK = 'ok';
    public const SOON = 'soon';
    public const DUE = 'due';

    /**
     * Rythme moyen (unités par jour) entre le premier et le dernier relevé fournis.
     * Null s'il n'y a pas au moins deux relevés espacés d'une journée.
     *
     * @param list<array{at: string, value: int}> $points relevés triés par date croissante
     */
    public static function dailyRate(array $points): ?float
    {
        if (count($points) < 2) {
            return null;
        }
        $first = $points[0];
        $last = $points[count($points) - 1];
        $days = (strtotime($last['at']) - strtotime($first['at'])) / 86400;
        if ($days < 1) {
            return null;
        }

        return max(0, $last['value'] - $first['value']) / $days;
    }

    /**
     * @return array{remaining: int, pct: int|null, days_left: int|null, due_on: string|null, status: string}
     */
    public static function project(int $current, int $dueAt, ?int $interval, ?float $dailyRate, DateTimeImmutable $today): array
    {
        $remaining = $dueAt - $current;
        $pct = $interval > 0 ? (int) round(max(0, $interval - $remaining) / $interval * 100) : null;
        $daysLeft = null;
        if ($remaining <= 0) {
            $daysLeft = 0;
        } elseif ($dailyRate > 0) {
            $daysLeft = (int) ceil($remaining / $dailyRate);
        }

        $status = self::OK;
        if ($remaining <= 0) {
            $status = self::DUE;
        } elseif (($pct !== null && $pct >= self::ALERT_RATIO * 100) || ($daysLeft !== null && $daysLeft <= self::ALERT_DAYS)) {
            $status = self::SOON;
        }

        return [
            'remaining' => $remaining,
            'pct' => $pct,
            'days_left' => $daysLeft,
            'due_on' => $daysLeft !== null && $remaining > 0 ? $today->modify("+$daysLeft days")->format('Y-m-d') : null,
            'status' => $status,
        ];
    }

    /** Jours écoulés depuis le dernier relevé d'index (null = jamais relevé). */
    public static function daysSince(?string $lastReadingAt, DateTimeImmutable $today): ?int
    {
        if ($lastReadingAt === null) {
            return null;
        }

        return max(0, (int) (new DateTimeImmutable(substr($lastReadingAt, 0, 10)))->diff($today)->format('%r%a'));
    }

    public static function isStale(?int $daysSince): bool
    {
        return $daysSince === null || $daysSince >= self::STALE_DAYS;
    }
}
