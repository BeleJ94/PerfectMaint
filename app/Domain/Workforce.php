<?php

declare(strict_types=1);

namespace App\Domain;

/** Référentiel du personnel de terrain : fonctions, shifts, types d'équipe. */
final class Workforce
{
    public const JOBS = ['driver', 'operator', 'mechanic', 'electrician', 'welder', 'tyre_fitter', 'lubricator', 'team_lead', 'dispatcher', 'supervisor', 'other'];

    /** Fonctions pouvant être affectées à la conduite d'un engin. */
    public const OPERATOR_JOBS = ['driver', 'operator'];

    /** Fonctions intervenant en maintenance (intervenants d'OT). */
    public const MAINTENANCE_JOBS = ['mechanic', 'electrician', 'welder', 'tyre_fitter', 'lubricator', 'team_lead', 'supervisor'];

    public const SHIFTS = ['day', 'night', 'rotation'];

    public const TEAM_KINDS = ['maintenance', 'operations'];

    private function __construct()
    {
    }
}
