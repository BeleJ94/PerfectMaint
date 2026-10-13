<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Échec d'envoi d'un e-mail : le message reprend le diagnostic du serveur SMTP. */
final class MailException extends RuntimeException
{
}
