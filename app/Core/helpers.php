<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Url;

/**
 * Échappe une valeur pour une sortie HTML (texte ou attribut).
 * À utiliser pour TOUTE donnée affichée dans une vue.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    if ($value instanceof BackedEnum) {
        $value = $value->value;
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Traduction via le catalogue i18n courant (non échappée : passer par e()). */
function t(string $key, array $params = []): string
{
    return I18n::current()->get($key, $params);
}

/** URL absolue depuis la racine de l'application (gère un sous-répertoire). */
function url(string $path = '/', array $query = []): string
{
    return Url::to($path, $query);
}

function asset(string $path): string
{
    return Url::asset($path);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::current()->token()) . '">';
}

function csrf_token(): string
{
    return Csrf::current()->token();
}

/** Encode des données pour un bloc <script type="application/json"> sans risque d'injection. */
function json_for_html(mixed $data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function format_date(?string $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    $format = I18n::current()->locale() === 'en'
        ? ($withTime ? 'Y-m-d H:i' : 'Y-m-d')
        : ($withTime ? 'd/m/Y H:i' : 'd/m/Y');

    return date($format, $ts);
}

/** Valeur à réafficher dans un champ : saisie précédente (après erreur) sinon valeur courante. */
function old(string $field, mixed $default = null): mixed
{
    return \App\Core\FormState::hasOld() ? \App\Core\FormState::old($field) : $default;
}

/** Message d'erreur de validation d'un champ, déjà traduit (non échappé). */
function field_error(string $field): ?string
{
    return \App\Core\FormState::error($field);
}

/** Libellé d'un statut / d'une énumération via le catalogue i18n. */
function label(string $group, ?string $value): string
{
    return $value === null || $value === '' ? '' : t($group . '.' . $value);
}

/** Pastille d'état d'engin (couleurs de la charte). */
function status_badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '';
    }

    return '<span class="badge badge--' . e($status) . '">' . e(label('status', $status)) . '</span>';
}

/** Pastille de statut d'OT : reprend la couleur de l'état d'engin correspondant. */
function wo_status_badge(?string $status): string
{
    $class = match ($status) {
        'open' => 'breakdown',
        'in_progress' => 'maintenance',
        'waiting_part' => 'waiting_part',
        'done' => 'available',
        default => 'neutral',
    };

    return '<span class="badge badge--' . $class . '">' . e(label('wo_status', $status)) . '</span>';
}

function priority_badge(?string $priority): string
{
    $class = in_array($priority, ['critical', 'high'], true) ? $priority : 'neutral';

    return '<span class="badge badge--' . $class . '">' . e(label('priority', $priority)) . '</span>';
}

/** Attribut aria-current pour la navigation. */
function nav_current(string $currentPath, string $prefix): string
{
    $active = $prefix === '/' ? $currentPath === '/' : ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/'));

    return $active ? ' aria-current="page"' : '';
}

function format_number(mixed $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $fr = \App\Core\I18n::current()->locale() !== 'en';

    return number_format((float) $value, $decimals, $fr ? ',' : '.', $fr ? "\u{202F}" : ',');
}

/** Quantité sans décimales inutiles (2,50 → 2,5 ; 3,00 → 3). */
function format_qty(mixed $value): string
{
    $float = (float) $value;
    $decimals = fmod($float, 1.0) === 0.0 ? 0 : (fmod($float * 10, 1.0) === 0.0 ? 1 : 2);

    return format_number($float, $decimals);
}
