<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Lecture du niveau de carburant sur l'indicateur du véhicule.
 *
 * - Indicateur à graduations (aiguille à N tirets, barres à N segments) : l'encodeur saisit la graduation
 *   atteinte (0 = vide, N = plein), demi-graduation acceptée. 2 sur 4 tirets avec un réservoir de 80 L = 40 L, 50 %.
 * - Indicateur numérique : l'encodeur saisit le pourcentage affiché.
 * Les litres ne sont connus que si la capacité du réservoir est renseignée.
 */
final class FuelGauge
{
    public const MARKS = 'marks';
    public const PERCENT = 'percent';

    public function __construct(
        public readonly string $kind,
        public readonly ?int $marks,
        public readonly ?int $capacityLiters,
        public readonly ?string $name = null,
    ) {
    }

    /** Indicateur non configuré : saisie en pourcentage, comme avant. */
    public static function unconfigured(?int $capacityLiters = null): self
    {
        return new self(self::PERCENT, null, $capacityLiters);
    }

    public function usesMarks(): bool
    {
        return $this->kind === self::MARKS && $this->marks > 0;
    }

    /** Graduation saisie valide : entre 0 et N, par pas d'une demi-graduation. */
    public function acceptsMark(float $mark): bool
    {
        return $this->usesMarks() && $mark >= 0 && $mark <= $this->marks && fmod($mark * 2, 1.0) === 0.0;
    }

    /**
     * @return array{fuel_level: int, fuel_mark: float, fuel_marks: int, fuel_liters: float|null}
     */
    public function fromMark(float $mark): array
    {
        if (!$this->acceptsMark($mark)) {
            throw new \InvalidArgumentException('Graduation hors de l\'indicateur.');
        }
        $ratio = $mark / $this->marks;

        return [
            'fuel_level' => (int) round($ratio * 100),
            'fuel_mark' => $mark,
            'fuel_marks' => $this->marks,
            'fuel_liters' => $this->capacityLiters !== null ? round($ratio * $this->capacityLiters, 1) : null,
        ];
    }

    /**
     * @return array{fuel_level: int, fuel_mark: null, fuel_marks: null, fuel_liters: float|null}
     */
    public function fromPercent(int $percent): array
    {
        $percent = max(0, min(100, $percent));

        return [
            'fuel_level' => $percent,
            'fuel_mark' => null,
            'fuel_marks' => null,
            'fuel_liters' => $this->capacityLiters !== null ? round($percent / 100 * $this->capacityLiters, 1) : null,
        ];
    }
}
