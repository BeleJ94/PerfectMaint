<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Csrf;
use App\Core\Session;
use App\Domain\Role;
use App\Repositories\IdentityRepository;

/**
 * Authentification par session, verrouillage après échecs répétés.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user_id';

    private ?CurrentUser $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly IdentityRepository $identities,
        private readonly Csrf $csrf,
        private readonly array $security = ['max_login_attempts' => 5, 'lockout_minutes' => 15],
    ) {
    }

    public function user(): ?CurrentUser
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $id = $this->session->get(self::SESSION_KEY);
            if (is_int($id)) {
                $row = $this->identities->findActive($id);
                $this->user = $row === null ? null : $this->hydrate($row);
                if ($this->user === null) {
                    $this->session->remove(self::SESSION_KEY);
                }
            }
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function scope(): SiteScope
    {
        return $this->user()?->scope() ?? SiteScope::none();
    }

    /**
     * @return array{ok: bool, error?: string, user?: CurrentUser}
     *   error = clé i18n (message volontairement générique pour ne pas révéler l'existence du compte)
     */
    public function attempt(string $email, string $password): array
    {
        $row = $this->identities->findByEmail(trim($email));
        if ($row === null) {
            // Vérification factice : même durée de réponse que pour un compte existant.
            password_verify($password, '$2y$10$tK6nKHd3NnV9s1/.xCmune9CDFKcd6NhQj4nkFQ44RGqGAAG.Q1s6');

            return ['ok' => false, 'error' => 'auth.invalid'];
        }
        if (!(bool) $row['is_active']) {
            return ['ok' => false, 'error' => 'auth.invalid'];
        }
        if ($row['locked_until'] !== null && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'auth.locked'];
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->identities->recordFailure(
                (int) $row['id'],
                (int) $this->security['max_login_attempts'],
                (int) $this->security['lockout_minutes']
            );

            return ['ok' => false, 'error' => 'auth.invalid'];
        }

        $this->identities->recordSuccess((int) $row['id']);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_KEY, (int) $row['id']);
        $this->session->set('locale', (string) $row['locale']);
        $this->user = $this->hydrate($row);
        $this->resolved = true;

        return ['ok' => true, 'user' => $this->user];
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->user = null;
        $this->resolved = true;
    }

    private function hydrate(array $row): CurrentUser
    {
        return new CurrentUser(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            Role::from((string) $row['role']),
            (string) $row['locale'],
            (bool) $row['all_sites'],
            $this->identities->siteIds((int) $row['id']),
        );
    }
}
