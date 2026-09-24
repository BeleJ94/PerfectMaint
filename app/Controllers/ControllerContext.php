<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\I18n;
use App\Core\Session;
use App\Core\View;
use App\Security\Auth;

/** Dépendances communes aux contrôleurs, injectées en un seul objet. */
final class ControllerContext
{
    public function __construct(
        public readonly View $view,
        public readonly Auth $auth,
        public readonly Session $session,
        public readonly I18n $i18n,
    ) {
    }
}
