<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\ActivityLogRepository;
use App\Security\Auth;

/**
 * Journal d'activité : qui (utilisateur), quoi (entité + action + résumé), quand,
 * avant/après (seuls les champs modifiés pour une mise à jour).
 */
final class ActivityLogger
{
    /** Champs jamais journalisés. */
    private const REDACTED = ['password', 'password_hash', 'password_confirmation', '_csrf'];

    /** Champs techniques non significatifs pour un diff. */
    private const IGNORED = ['updated_at', 'created_at'];

    public function __construct(
        private readonly ActivityLogRepository $repository,
        private readonly Auth $auth,
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * @param string|null $actor nom affiché quand aucune session n'est ouverte (ex. identifiant saisi lors d'un échec de connexion)
     * @return int|null identifiant de l'entrée, null si aucune modification réelle
     */
    public function log(
        string $action,
        string $entityType,
        ?int $entityId,
        ?int $siteId,
        ?array $before = null,
        ?array $after = null,
        ?string $summary = null,
        ?string $actor = null,
    ): ?int {
        if ($before !== null && $after !== null) {
            [$before, $after] = self::diff($before, $after);
            if ($before === [] && $after === []) {
                return null;
            }
        }
        $user = $this->auth->user();

        return $this->repository->insert([
            'user_id' => $user?->id,
            'user_name' => $user?->name() ?? ($actor !== null ? mb_substr($actor, 0, 170) : 'system'),
            'site_id' => $siteId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'summary' => $summary,
            'before_data' => self::encode($before),
            'after_data' => self::encode($after),
            'ip' => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent(),
        ]);
    }

    /**
     * Ne conserve que les champs dont la valeur a changé (comparaison en chaîne : "5" == 5).
     * Seuls les champs écrits ($after) sont comparés : une ligne complète peut être passée en « avant ».
     * « Avant » ne contient que les champs présents dans l'état initial.
     *
     * @return array{0: array, 1: array}
     */
    public static function diff(array $before, array $after): array
    {
        $b = [];
        $a = [];
        foreach (array_keys($after) as $key) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (self::normalize($old) !== self::normalize($new)) {
                // Un champ de contexte absent de l'état initial (motif, commentaire…) n'apparaît qu'en « après ».
                if (array_key_exists($key, $before)) {
                    $b[$key] = $old;
                }
                $a[$key] = $new;
            }
        }

        return [$b, $a];
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        if (is_array($value)) {
            return json_encode($value) ?: null;
        }

        return (string) $value;
    }

    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '***';
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
